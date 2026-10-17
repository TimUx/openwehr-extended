<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_files_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $this->location($table);
            $table->bigInteger('quota_bytes')->nullable();
            $table->timestamps();
        });
        Schema::create('x_files_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('x_files_spaces')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('x_files_nodes')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 10);
            $table->boolean('inherit_permissions')->default(true);
            $table->softDeletesTz();
            $table->timestamps();
            $table->index(['space_id', 'parent_id']);
        });
        Schema::create('x_files_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('x_files_nodes')->cascadeOnDelete();
            $table->integer('number');
            $table->string('storage_key')->unique();
            $table->string('checksum', 64);
            $table->bigInteger('size');
            $table->string('mime');
            $table->string('extension', 10);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 20)->default('upload');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['node_id', 'number']);
        });
        Schema::create('x_files_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $this->location($table);
            $table->timestamps();
        });
        Schema::create('x_files_group_members', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('x_files_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_manager')->default(false);
            $table->primary(['group_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('x_files_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 10);
            $table->bigInteger('subject_id');
            $table->string('target_type', 10);
            $table->bigInteger('target_id');
            $table->string('level', 10);
            // Filled by a trigger: actual FKs enforce polymorphic references, including cascading deletes.
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_group_id')->nullable()->constrained('x_files_groups')->cascadeOnDelete();
            $table->foreignId('target_space_id')->nullable()->constrained('x_files_spaces')->cascadeOnDelete();
            $table->foreignId('target_node_id')->nullable()->constrained('x_files_nodes')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'target_type', 'target_id'], 'x_files_permission_unique');
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('x_files_audit_events', function (Blueprint $table) {
            $table->id();
            // Historical identifiers deliberately have no FKs: purging must not rewrite the audit.
            $table->bigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->bigInteger('space_id')->nullable();
            $table->bigInteger('node_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['space_id', 'created_at']);
            $table->index(['node_id', 'created_at']);
        });
        Schema::create('x_files_pending_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('storage_key')->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE x_files_spaces ADD CONSTRAINT x_files_quota_positive CHECK (quota_bytes IS NULL OR quota_bytes >= 0);
            ALTER TABLE x_files_nodes ADD CONSTRAINT x_files_kind CHECK (kind IN ('folder', 'file'));
            ALTER TABLE x_files_nodes ADD CONSTRAINT x_files_safe_name CHECK (
                length(btrim(name)) > 0 AND name NOT IN ('.', '..')
                AND name !~ '[/\\]' AND name !~ '[[:cntrl:]]'
            );
            CREATE UNIQUE INDEX x_files_active_name ON x_files_nodes (space_id, COALESCE(parent_id, 0), lower(name)) WHERE deleted_at IS NULL;
            ALTER TABLE x_files_versions ADD CONSTRAINT x_files_version_valid CHECK (
                number > 0 AND size >= 0 AND checksum ~ '^[0-9a-f]{64}$'
                AND storage_key ~ '^[0-9a-f-]{36}/[0-9a-f-]{36}$'
                AND source IN ('upload', 'restore')
            );
            ALTER TABLE x_files_permissions ADD CONSTRAINT x_files_permission_valid CHECK (
                subject_type IN ('user', 'group') AND target_type IN ('space', 'node')
                AND level IN ('read', 'edit', 'manage')
            );

            CREATE OR REPLACE FUNCTION x_files_permission_references() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.subject_user_id := CASE WHEN NEW.subject_type = 'user' THEN NEW.subject_id END;
                NEW.subject_group_id := CASE WHEN NEW.subject_type = 'group' THEN NEW.subject_id END;
                NEW.target_space_id := CASE WHEN NEW.target_type = 'space' THEN NEW.target_id END;
                NEW.target_node_id := CASE WHEN NEW.target_type = 'node' THEN NEW.target_id END;
                RETURN NEW;
            END $$;
            CREATE TRIGGER x_files_permission_references BEFORE INSERT OR UPDATE ON x_files_permissions
                FOR EACH ROW EXECUTE FUNCTION x_files_permission_references();

            CREATE OR REPLACE FUNCTION x_files_guard_tree() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_space bigint; parent_kind text; has_cycle boolean;
            BEGIN
                PERFORM 1 FROM x_files_spaces WHERE id = NEW.space_id FOR UPDATE;
                IF TG_OP = 'UPDATE' AND (NEW.space_id <> OLD.space_id OR NEW.kind <> OLD.kind) THEN
                    RAISE EXCEPTION 'File node space and kind are immutable';
                END IF;
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT space_id, kind INTO parent_space, parent_kind FROM x_files_nodes WHERE id = NEW.parent_id;
                    IF parent_space IS DISTINCT FROM NEW.space_id OR parent_kind IS DISTINCT FROM 'folder' THEN
                        RAISE EXCEPTION 'File parent must be a folder in the same space';
                    END IF;
                    WITH RECURSIVE ancestors AS (
                        SELECT id, parent_id FROM x_files_nodes WHERE id = NEW.parent_id
                        UNION
                        SELECT n.id, n.parent_id FROM x_files_nodes n JOIN ancestors a ON n.id = a.parent_id
                    ) SELECT EXISTS(SELECT 1 FROM ancestors WHERE id = NEW.id) INTO has_cycle;
                    IF has_cycle THEN RAISE EXCEPTION 'File tree cycle'; END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER x_files_guard_tree BEFORE INSERT OR UPDATE ON x_files_nodes
                FOR EACH ROW EXECUTE FUNCTION x_files_guard_tree();

            CREATE OR REPLACE FUNCTION x_files_guard_version() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'File versions are immutable'; END IF;
                IF NOT EXISTS(SELECT 1 FROM x_files_nodes WHERE id = NEW.node_id AND kind = 'file' AND deleted_at IS NULL) THEN
                    RAISE EXCEPTION 'File versions require an active file';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER x_files_guard_version BEFORE INSERT OR UPDATE ON x_files_versions
                FOR EACH ROW EXECUTE FUNCTION x_files_guard_version();

            CREATE OR REPLACE FUNCTION x_files_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'File audit events are append only'; END $$;
            CREATE TRIGGER x_files_append_only BEFORE UPDATE OR DELETE ON x_files_audit_events
                FOR EACH ROW EXECUTE FUNCTION x_files_append_only();
            SQL);

        DB::table('modules')->upsert(
            [['key' => 'dateien', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'dateien')->delete();
        foreach (['x_files_pending_deletions', 'x_files_audit_events', 'x_files_permissions', 'x_files_group_members', 'x_files_groups', 'x_files_versions', 'x_files_nodes', 'x_files_spaces'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['x_files_permission_references', 'x_files_guard_tree', 'x_files_guard_version', 'x_files_append_only'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }

    private function location(Blueprint $table): void
    {
        if (Schema::hasTable('x_locations')) {
            $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->restrictOnDelete();
        } else {
            $table->unsignedBigInteger('ext_location_id')->nullable();
        }
    }
};

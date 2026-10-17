<?php

namespace Extensions\Dateien\Livewire;

use App\Models\User;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Explorer extends Component
{
    use WithFileUploads;

    protected $validationAttributes = [
        'folderName' => 'Ordnername',
        'name' => 'Name',
        'destination' => 'Zielordner',
        'upload' => 'Datei',
        'subjectType' => 'Empfängertyp',
        'subjectId' => 'Empfänger',
        'permissionLevel' => 'Berechtigung',
    ];

    #[Url]
    public ?int $space = null;

    #[Url]
    public ?int $node = null;

    #[Url]
    public string $view = '';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $expectedVersion = null;

    public string $name = '';

    public string $folderName = '';

    public string $destination = '';

    public string $subjectType = 'user';

    public string $subjectId = '';

    public string $permissionLevel = 'read';

    public bool $inheritPermissions = true;

    public $upload = null;

    public function mount(): void
    {
        if ($this->node !== null) {
            $node = $this->findNode($this->node);
            if ($node->kind === 'file') {
                $this->select($node->id);
            }
        }
    }

    public function navigate(?int $space = null, ?int $node = null, string $view = ''): void
    {
        if ($node !== null) {
            $folder = Node::query()->findOrFail($node);
            $this->access()->authorize($this->user(), $folder, 'read');
            abort_unless($folder->kind === 'folder', 404);
            $folderSpace = Space::query()->findOrFail($folder->space_id);
            $space = $this->access()->can($this->user(), $folderSpace, 'read') ? (int) $folderSpace->id : null;
        } elseif ($space !== null) {
            $this->access()->authorize($this->user(), Space::query()->findOrFail($space), 'read');
        }

        abort_unless(in_array($view, ['', 'shared', 'trash'], true), 404);
        $this->space = $space;
        $this->node = $node;
        $this->view = $view;
        $this->resetSelection();
    }

    public function select(int $id): void
    {
        $node = $this->findNode($id, $this->view === 'trash');
        $this->selectedId = $node->id;
        $this->name = $node->name;
        $this->inheritPermissions = (bool) $node->inherit_permissions;
        $this->destination = '';
        $this->expectedVersion = $node->kind === 'file' && ! $node->trashed()
            ? $this->service()->versions($this->user(), $node)->max('number')
            : null;
        $this->resetValidation();
    }

    public function resetSelection(): void
    {
        $this->reset('selectedId', 'expectedVersion', 'name', 'destination', 'subjectId', 'upload');
        $this->resetValidation();
    }

    public function createFolder(): void
    {
        [$space, $parent] = $this->context('edit');
        $this->validate(['folderName' => ['required', 'string', 'max:255']]);
        $this->service()->createFolder($this->user(), $space, $parent, $this->folderName);
        $this->reset('folderName');
        session()->flash('flash', 'Ordner erstellt.');
    }

    public function uploadFile(): void
    {
        [$space, $parent] = $this->context('edit');
        $this->validateUpload();
        $this->service()->upload($this->user(), $space, $parent, $this->upload);
        $this->reset('upload');
        session()->flash('flash', 'Datei hochgeladen.');
    }

    public function rename(): void
    {
        $node = $this->selected('edit');
        $this->validate(['name' => ['required', 'string', 'max:255']]);
        $this->service()->rename($this->user(), $node, $this->name);
        session()->flash('flash', 'Name geändert.');
    }

    public function move(): void
    {
        $node = $this->selected('manage');
        $target = null;
        if ($this->destination !== '') {
            $this->validate(['destination' => ['integer', 'min:1']]);
            $target = Node::query()->where('space_id', $node->space_id)
                ->where('kind', 'folder')->findOrFail((int) $this->destination);
            $this->access()->authorize($this->user(), $target, 'manage');
        } else {
            $this->access()->authorize($this->user(), Space::query()->findOrFail($node->space_id), 'manage');
        }
        $this->service()->move($this->user(), $node, $target);
        $this->resetSelection();
        session()->flash('flash', 'Eintrag verschoben.');
    }

    public function addVersion(): void
    {
        $node = $this->selected('edit');
        abort_unless($node->kind === 'file', 404);
        $this->validateUpload();
        $this->service()->addVersion($this->user(), $node, $this->upload, $this->expectedVersion);
        $this->reset('upload');
        $this->select($node->id);
        session()->flash('flash', 'Neue Version gespeichert.');
    }

    public function restoreVersion(int $id): void
    {
        $node = $this->selected('edit');
        $version = Version::query()->where('node_id', $node->id)->findOrFail($id);
        $this->service()->restoreVersion($this->user(), $node, $version, $this->expectedVersion);
        $this->select($node->id);
        session()->flash('flash', 'Version als neue Version wiederhergestellt.');
    }

    public function trash(): void
    {
        $node = $this->selected('manage');
        $this->service()->trash($this->user(), $node);
        if ($this->node === (int) $node->id) {
            $this->node = null;
            $this->space = null;
            $this->view = 'trash';
        }
        $this->resetSelection();
        session()->flash('flash', 'In den Papierkorb verschoben.');
    }

    public function restore(): void
    {
        $node = $this->selected('manage', true);
        abort_unless($node->trashed(), 404);
        $this->service()->restore($this->user(), $node);
        $this->resetSelection();
        session()->flash('flash', 'Wiederhergestellt.');
    }

    public function purge(): void
    {
        $node = $this->selected('manage', true);
        abort_unless($node->trashed(), 404);
        $this->service()->purge($this->user(), $node);
        $this->resetSelection();
        session()->flash('flash', 'Endgültig gelöscht.');
    }

    public function savePermission(): void
    {
        $target = $this->permissionTarget();
        $this->validate([
            'subjectType' => ['required', 'in:user,group'],
            'subjectId' => ['required', 'integer', 'min:1'],
            'permissionLevel' => ['nullable', 'in:read,edit,manage'],
        ]);
        $space = $target instanceof Space ? $target : Space::query()->findOrFail($target->space_id);
        $subjects = $this->subjects($space);
        abort_unless($subjects[$this->subjectType === 'user' ? 'users' : 'groups']->contains('id', (int) $this->subjectId), 403);
        $this->service()->setPermission($this->user(), $target, $this->subjectType, (int) $this->subjectId, $this->permissionLevel ?: null);
        $this->leaveInaccessibleContext();
        session()->flash('flash', 'Berechtigung gespeichert.');
    }

    public function saveInheritance(): void
    {
        $this->service()->setInheritance($this->user(), $this->selected('manage'), $this->inheritPermissions);
        $this->leaveInaccessibleContext();
        session()->flash('flash', 'Vererbung gespeichert.');
    }

    public function revokePermission(int $id): void
    {
        $target = $this->permissionTarget();
        $permission = $target->permissions()->findOrFail($id);
        $this->service()->setPermission($this->user(), $target, $permission->subject_type, (int) $permission->subject_id, null);
        $this->leaveInaccessibleContext();
        session()->flash('flash', 'Direkte Freigabe entzogen.');
    }

    public function updatedSubjectType(): void
    {
        $this->subjectId = '';
    }

    private function leaveInaccessibleContext(): void
    {
        if ($this->selectedId !== null) {
            $selected = Node::query()->find($this->selectedId);
            if (! $selected || ! $this->access()->can($this->user(), $selected, 'read')) {
                $this->resetSelection();
            }
        }
        $target = $this->node !== null
            ? Node::query()->find($this->node)
            : ($this->space !== null ? Space::query()->find($this->space) : null);
        if (($this->node !== null || $this->space !== null) && (! $target || ! $this->access()->can($this->user(), $target, 'read'))) {
            $this->navigate(null, null, 'shared');
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $this->access()->member($user);
    }

    private function access(): FileAccess
    {
        return app(FileAccess::class);
    }

    private function service(): FileService
    {
        return app(FileService::class);
    }

    private function findNode(int $id, bool $trashed = false): Node
    {
        $query = $trashed ? Node::withTrashed() : Node::query();
        $node = $query->findOrFail($id);
        if ($trashed && $node->trashed()) {
            $this->access()->authorizeTrashed($this->user(), $node);
        } else {
            $this->access()->authorize($this->user(), $node, 'read');
        }
        if ($this->space !== null) {
            abort_unless((int) $node->space_id === $this->space, 404);
        }

        return $node;
    }

    private function selected(string $level, bool $trashed = false): Node
    {
        abort_if($this->selectedId === null, 404);
        $node = $this->findNode($this->selectedId, $trashed);
        if ($trashed && $node->trashed()) {
            $this->access()->authorizeTrashed($this->user(), $node, 'manage');
        } else {
            $this->access()->authorize($this->user(), $node, $level);
        }

        return $node;
    }

    private function context(string $level = 'read'): array
    {
        $parent = $this->node !== null ? $this->findNode($this->node) : null;
        abort_if($parent && $parent->kind !== 'folder', 404);
        $space = Space::query()->findOrFail($parent?->space_id ?? $this->space);
        $this->access()->authorize($this->user(), $parent ?? $space, $level);

        return [$space, $parent];
    }

    private function permissionTarget(): Space|Node
    {
        if ($this->selectedId !== null) {
            return $this->selected('manage');
        }
        [$space, $parent] = $this->context('manage');

        return $parent ?? $space;
    }

    private function validateUpload(): void
    {
        $this->validate(['upload' => [
            'required', 'file', 'max:'.FileService::maxUploadKb(),
            'extensions:'.implode(',', FileService::allowedExtensions()),
        ]]);
    }

    private function subjects(Space $space): array
    {
        $users = User::query()->where('is_active', true);
        if (config('locations.enabled') && $space->ext_location_id !== null) {
            $location = (int) $space->ext_location_id;
            $users->where(function ($query) use ($location) {
                $query->where('ext_location_id', $location)
                    ->orWhereIn('id', \Illuminate\Support\Facades\DB::table('x_location_user')->where('location_id', $location)->select('user_id'));
            });
        }
        $groups = FileGroup::query();
        if (config('locations.enabled') && $space->ext_location_id !== null) {
            $groups->where(fn ($query) => $query->whereNull('ext_location_id')->orWhere('ext_location_id', $space->ext_location_id));
        }
        if (config('locations.enabled') && ! $this->user()->is_admin) {
            $locations = app(LocationAccess::class)->visibleLocationIds($this->user());
            $groups->where(fn ($query) => $query->whereNull('ext_location_id')->orWhereIn('ext_location_id', $locations));
        }

        return [
            'users' => $users->orderBy('name')->get(['id', 'name']),
            'groups' => $groups->orderBy('name')->get(),
        ];
    }

    private function breadcrumbs(Node $folder): Collection
    {
        $crumbs = collect();
        $seen = [];
        $current = $folder;
        while ($current && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            if (! $this->access()->can($this->user(), $current, 'read')) {
                break;
            }
            $crumbs->prepend($current);
            $current = $current->parent_id ? Node::query()->find($current->parent_id) : null;
        }

        return $crumbs;
    }

    public function render()
    {
        $user = $this->user();
        abort_unless(in_array($this->view, ['', 'shared', 'trash'], true), 404);
        $access = $this->access();
        $spaces = Space::query()->orderBy('name')->get()->filter(fn (Space $space) => $access->can($user, $space, 'read'));
        $space = null;
        $folder = null;
        $rows = collect();
        if ($this->node !== null || $this->space !== null) {
            $requested = $this->node !== null ? $this->findNode($this->node) : null;
            if ($requested?->kind === 'file') {
                $space = Space::query()->findOrFail($requested->space_id);
            } else {
                [$space, $folder] = $this->context();
                $rows = $this->view === 'trash'
                    ? Node::onlyTrashed()->where('space_id', $space->id)->orderBy('name')->get()
                        ->filter(fn (Node $node) => $this->canManageTrashed($node))
                    : $this->service()->children($user, $space, $folder);
            }
        } elseif ($this->view === 'shared') {
            $rows = $this->service()->shared($user);
        } elseif ($this->view === 'trash') {
            $rows = Node::onlyTrashed()->orderBy('name')->get()
                ->filter(fn (Node $node) => $this->canManageTrashed($node));
        }
        $selected = $this->selectedId !== null ? $this->selected('read', $this->view === 'trash') : null;
        $target = $selected ?? $folder ?? $space;
        $canManage = $target && ! ($target instanceof Node && $target->trashed()) && $access->can($user, $target, 'manage');
        $subjects = $canManage ? $this->subjects($space ?? Space::query()->findOrFail($selected->space_id)) : ['users' => collect(), 'groups' => collect()];
        $destinations = $selected && ! $selected->trashed() && $access->can($user, $selected, 'manage')
            ? Node::query()->where('space_id', $selected->space_id)->where('kind', 'folder')->whereKeyNot($selected->id)->orderBy('name')->get()
                ->filter(fn (Node $node) => $access->can($user, $node, 'manage'))
            : collect();

        return view('dateien::livewire.explorer', [
            'spaces' => $spaces, 'currentSpace' => $space, 'folder' => $folder, 'rows' => $rows,
            'selected' => $selected, 'target' => $target, 'access' => $access, 'user' => $user,
            'breadcrumbs' => $folder ? $this->breadcrumbs($folder) : collect(),
            'canWriteHere' => $this->view !== 'trash' && ($this->node === null || $folder) && ($folder || $space) && $access->can($user, $folder ?? $space, 'edit'),
            'canManage' => $canManage, 'subjects' => $subjects, 'destinations' => $destinations,
            'versions' => $selected && $selected->kind === 'file' && ! $selected->trashed() ? $this->service()->versions($user, $selected) : collect(),
            'permissions' => $canManage ? $target->permissions()->get() : collect(),
            'effectiveLevel' => $target ? $access->level($user, $target) : null,
            'purgeAfter' => $selected?->trashed() ? $selected->deleted_at->copy()->addDays(FileService::retentionDays()) : null,
            'retentionDays' => FileService::retentionDays(),
        ]);
    }

    private function canManageTrashed(Node $node): bool
    {
        try {
            $this->access()->authorizeTrashed($this->user(), $node);

            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
    }
}

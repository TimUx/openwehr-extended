<?php

namespace Extensions\Dateien\Filament;

use App\Filament\Concerns\IsModuleArea;
use App\Filament\ModuleArea;
use App\Models\Setting;
use App\Models\User;
use App\Support\FileResponse;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\GroupService;
use Extensions\Locations\Models\Location;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

class DateienPage extends Page implements HasForms, ModuleArea
{
    use InteractsWithForms;
    use IsModuleArea;

    protected static ?string $slug = 'dateien';

    protected static ?string $title = 'Dateien verwalten';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|\UnitEnum|null $navigationGroup = 'Dateien';

    protected string $view = 'dateien::filament.dateien';

    public ?array $data = [];

    #[Locked]
    public ?int $groupId = null;

    public string $groupName = '';

    public ?int $groupLocationId = null;

    public ?int $memberId = null;

    public bool $memberIsManager = false;

    public string $spaceName = '';

    public ?int $spaceLocationId = null;

    public ?int $quotaMb = null;

    public static function allows(User $user): bool
    {
        return GroupService::canManage($user);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill([
            'max_upload_kb' => FileService::maxUploadKb(),
            'extensions' => FileService::allowedExtensions(),
            'retention_days' => FileService::retentionDays(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Forms\Components\TextInput::make('max_upload_kb')->label('Maximale Dateigröße (KiB)')
                ->numeric()->required()->minValue(1)->maxValue(51200),
            Forms\Components\CheckboxList::make('extensions')->label('Erlaubte Dateiendungen')
                ->options(array_combine(FileResponse::allowedExtensions(), FileResponse::allowedExtensions()))
                ->required()->minItems(1),
            Forms\Components\TextInput::make('retention_days')->label('Mindestaufbewahrung im Papierkorb (Tage)')
                ->numeric()->required()->minValue(0)->maxValue(3650)
                ->helperText('Keine automatische Löschung. Erst nach dieser Frist ist eine explizite endgültige Löschung möglich.'),
        ]);
    }

    public function saveSettings(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        $state = $this->form->getState();
        $this->validate([
            'data.max_upload_kb' => ['required', 'integer', 'min:1', 'max:51200'],
            'data.extensions' => ['required', 'array', 'min:1'],
            'data.extensions.*' => ['required', 'string', 'in:'.implode(',', FileResponse::allowedExtensions())],
            'data.retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);
        Setting::set('dateien.max_upload_kb', (int) $state['max_upload_kb']);
        Setting::set('dateien.extensions', json_encode(array_values($state['extensions']), JSON_THROW_ON_ERROR));
        Setting::set('dateien.retention_days', (int) $state['retention_days']);
        Notification::make()->title('Einstellungen gespeichert')->success()->send();
    }

    public function editGroup(int $id): void
    {
        $this->authorizeManager();
        $group = app(GroupService::class)->groups(auth()->user())->firstWhere('id', $id);
        abort_unless($group, 403);
        $this->groupId = $group->id;
        $this->groupName = $group->name;
        $this->groupLocationId = $group->ext_location_id;
    }

    public function newGroup(): void
    {
        $this->authorizeManager();
        $this->reset('groupId', 'groupName', 'groupLocationId', 'memberId', 'memberIsManager');
    }

    public function saveGroup(): void
    {
        $this->authorizeManager();
        $group = app(GroupService::class)->save(
            auth()->user(),
            $this->groupId ? FileGroup::findOrFail($this->groupId) : null,
            $this->groupName,
            $this->groupLocationId,
        );
        $this->groupId = $group->id;
        Notification::make()->title('Dateigruppe gespeichert')->success()->send();
    }

    public function saveMember(): void
    {
        $this->authorizeManager();
        $this->validate(['memberId' => ['required', 'integer']]);
        app(GroupService::class)->setMember(auth()->user(), FileGroup::findOrFail($this->groupId), $this->memberId, $this->memberIsManager);
        $this->reset('memberId', 'memberIsManager');
    }

    public function removeMember(int $id): void
    {
        $this->authorizeManager();
        app(GroupService::class)->setMember(auth()->user(), FileGroup::findOrFail($this->groupId), $id, null);
    }

    public function createSpace(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        $this->validate([
            'spaceName' => ['required', 'string', 'max:150'],
            'quotaMb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'spaceLocationId' => ['nullable', 'integer', 'exists:x_locations,id'],
        ]);
        app(FileService::class)->createSpace(auth()->user(), [
            'name' => $this->spaceName,
            'ext_location_id' => config('locations.enabled') ? $this->spaceLocationId : null,
            'quota_bytes' => $this->quotaMb === null ? null : $this->quotaMb * 1024 * 1024,
        ]);
        $this->reset('spaceName', 'spaceLocationId', 'quotaMb');
        Notification::make()->title('Ablage erstellt')->success()->send();
    }

    public function retryDeletions(): void
    {
        $this->authorizeManager();
        abort_unless(auth()->user()->is_admin, 403);
        app(FileService::class)->retryPendingDeletions(auth()->user());
        Notification::make()->title('Ausstehende Dateilöschungen erneut versucht')->success()->send();
    }

    protected function getViewData(): array
    {
        $this->authorizeManager();
        $groups = app(GroupService::class)->groups(auth()->user());
        $group = $groups->firstWhere('id', $this->groupId);

        return [
            'groups' => $groups,
            'selectedGroup' => $group,
            'members' => $group ? $group->members()->get() : collect(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'locations' => config('locations.enabled') && auth()->user()->is_admin ? Location::orderBy('name')->get() : collect(),
        ];
    }

    private function authorizeManager(): void
    {
        abort_unless(self::canAccess(), 403);
    }
}

<x-filament-panels::page>
    <p><a href="{{ route('dateien.index') }}">Dateiablage öffnen</a></p>
    <section style="display: grid; gap: 1rem;">
        <h2>Dateigruppen</h2>
        <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
            @foreach ($groups as $group)
                <x-filament::button color="gray" wire:click="editGroup({{ $group->id }})">{{ $group->name }}</x-filament::button>
            @endforeach
            <x-filament::button wire:click="newGroup">Neue Dateigruppe</x-filament::button>
        </div>
        <form wire:submit="saveGroup" style="display: grid; gap: .5rem;">
            <label>Name <x-filament::input.wrapper><x-filament::input wire:model="groupName" maxlength="150" required /></x-filament::input.wrapper></label>
            @error('name') <p role="alert">{{ $message }}</p> @enderror
            @if (auth()->user()->is_admin && !$groupId && $locations->isNotEmpty())
                <label>Standort
                    <x-filament::input.wrapper><x-filament::input.select wire:model="groupLocationId">
                        <option value="">Gemeinsam (alle Standorte)</option>
                        @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
            @endif
            <div><x-filament::button type="submit">Dateigruppe speichern</x-filament::button></div>
        </form>
        @if ($selectedGroup)
            <h3>Mitglieder: {{ $selectedGroup->name }}</h3>
            @foreach ($members as $member)
                <div wire:key="member-{{ $member->id }}" style="display: flex; gap: 1rem; align-items: center;">
                    <span>{{ $member->name }}{{ $member->pivot->is_manager ? ' (Gruppenverwalter)' : '' }}</span>
                    <x-filament::button color="danger" wire:click="removeMember({{ $member->id }})" wire:confirm="Mitglied aus dieser Dateigruppe entfernen?">Entfernen</x-filament::button>
                </div>
            @endforeach
            <form wire:submit="saveMember" style="display: grid; gap: .5rem;">
                <label>Mitglied
                    <x-filament::input.wrapper><x-filament::input.select wire:model="memberId" required>
                        <option value="">Bitte wählen</option>
                        @foreach ($users as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                @error('memberId') <p role="alert">{{ $message }}</p> @enderror
                <label><x-filament::input.checkbox wire:model="memberIsManager" /> Als Gruppenverwalter kennzeichnen</label>
                <p>Das Kennzeichen dokumentiert die Zuständigkeit. Die Verwaltung erfordert weiterhin die Modulrolle „gruppenverwalter“ oder globale Adminrechte.</p>
                <div><x-filament::button type="submit">Mitglied hinzufügen / aktualisieren</x-filament::button></div>
            </form>
        @endif
    </section>
    @if (auth()->user()->is_admin)
        <section style="display: grid; gap: 1rem;">
            <h2>Ablage anlegen</h2>
            <form wire:submit="createSpace" style="display: grid; gap: .5rem;">
                <label>Name <x-filament::input.wrapper><x-filament::input wire:model="spaceName" required maxlength="150" /></x-filament::input.wrapper></label>
                @error('spaceName') <p role="alert">{{ $message }}</p> @enderror
                @if ($locations->isNotEmpty())
                    <label>Standort
                        <x-filament::input.wrapper><x-filament::input.select wire:model="spaceLocationId">
                            <option value="">Gemeinsam (alle Standorte)</option>
                            @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                @endif
                <label>Kontingent (MiB, leer = unbegrenzt) <x-filament::input.wrapper><x-filament::input type="number" wire:model="quotaMb" min="1" /></x-filament::input.wrapper></label>
                @error('quotaMb') <p role="alert">{{ $message }}</p> @enderror
                <div><x-filament::button type="submit">Ablage anlegen</x-filament::button></div>
            </form>
        </section>
        <form wire:submit="saveSettings" style="display: grid; gap: 1rem;">
            <h2>Basiskonfiguration</h2>
            {{ $this->form }}
            <div><x-filament::button type="submit">Einstellungen speichern</x-filament::button></div>
        </form>
        <section style="display: grid; gap: .5rem;">
            <h2>Speicherbereinigung</h2>
            <p>Falls die physische Löschung nach einer endgültigen Löschung fehlgeschlagen ist, kann sie hier erneut versucht werden. Aktive Versionen werden nicht gelöscht.</p>
            <div><x-filament::button wire:click="retryDeletions">Ausstehende Dateilöschungen erneut versuchen</x-filament::button></div>
        </section>
    @endif
</x-filament-panels::page>

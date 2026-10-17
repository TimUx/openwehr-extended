@php
    $input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red';
    $button = 'inline-flex items-center rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white disabled:opacity-50';
    $link = 'text-sm font-medium text-fw-navy underline';
@endphp
<div class="mx-auto max-w-6xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">Dateien</h1>
    @if(session('flash'))
        <p role="status" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('flash') }}</p>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <div class="grid gap-4 md:grid-cols-[14rem_minmax(0,1fr)]">
        <aside class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Bereiche</h2>
            @forelse($spaces as $item)
                <button type="button" wire:click="navigate({{ $item->id }})" class="block w-full break-words text-left text-sm text-fw-navy">{{ $item->name }}</button>
            @empty
                <p class="text-sm text-gray-500">Keine freigegebenen Bereiche.</p>
            @endforelse
            <hr class="border-gray-100">
            <button type="button" wire:click="navigate(null, null, 'shared')" class="block text-sm text-fw-navy">Mit mir geteilt</button>
            <button type="button" wire:click="navigate(null, null, 'trash')" class="block text-sm text-fw-navy">Papierkorb</button>
        </aside>
        <main class="min-w-0 space-y-4">
            <div class="flex flex-wrap items-center gap-2 text-sm" aria-label="Ordnerpfad">
                @if($currentSpace && $access->can($user, $currentSpace, 'read'))
                    <button type="button" wire:click="navigate({{ $currentSpace->id }})" class="{{ $link }}">{{ $currentSpace->name }}</button>
                @elseif($view === 'shared' || $folder)
                    <button type="button" wire:click="navigate(null, null, 'shared')" class="{{ $link }}">Mit mir geteilt</button>
                @elseif($view !== 'trash')
                    <span class="text-gray-500">Bitte einen Bereich auswählen.</span>
                @endif
                @foreach($breadcrumbs as $crumb)
                    <span aria-hidden="true">/</span>
                    <button type="button" wire:click="navigate(null, {{ $crumb->id }})" class="{{ $link }}">{{ $crumb->name }}</button>
                @endforeach
                @if($view === 'trash')<span class="font-semibold">Papierkorb</span>@endif
                @if($currentSpace && $view !== 'trash' && $access->can($user, $currentSpace, 'manage'))
                    <button type="button" wire:click="navigate({{ $currentSpace->id }}, null, 'trash')" class="{{ $link }} ml-auto">Papierkorb dieses Bereichs</button>
                @endif
            </div>
            @if($effectiveLevel)
                <p class="text-sm text-gray-600">Ihr Recht: {{ ['read' => 'Lesen', 'edit' => 'Bearbeiten', 'manage' => 'Verwalten'][$effectiveLevel] }}</p>
            @endif
            @if($view === 'trash')
                <p class="text-sm text-gray-500">Aufbewahrungsfrist im Papierkorb: {{ $retentionDays }} Tage. Danach können Einträge endgültig gelöscht werden. Das kann nicht rückgängig gemacht werden.</p>
            @endif
            @if($canWriteHere)
                <div class="grid gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm lg:grid-cols-2">
                    <form wire:submit="createFolder" class="flex flex-wrap items-end gap-2">
                        <label class="min-w-0 flex-1 text-sm">Neuer Ordner
                            <input wire:model="folderName" required maxlength="255" class="{{ $input }} mt-1 w-full">
                        </label>
                        <button class="{{ $button }}" wire:loading.attr="disabled">Erstellen</button>
                    </form>
                    <form wire:submit="uploadFile" class="flex min-w-0 flex-wrap items-end gap-2">
                        <label class="min-w-0 flex-1 text-sm">Datei hochladen
                            <input type="file" wire:model="upload" required class="mt-1 block w-full text-sm">
                        </label>
                        <button class="{{ $button }}" wire:loading.attr="disabled">Hochladen</button>
                    </form>
                </div>
            @endif
            <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                @forelse($rows as $row)
                    <div wire:key="node-{{ $row->id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            @if($row->kind === 'folder' && $view !== 'trash')
                                <button type="button" wire:click="navigate(null, {{ $row->id }})" class="break-words text-left font-semibold text-fw-navy">{{ $row->name }}</button>
                            @else
                                <button type="button" wire:click="select({{ $row->id }})" class="break-words text-left font-semibold text-gray-900">{{ $row->name }}</button>
                            @endif
                            <p class="text-xs text-gray-500">{{ $row->kind === 'folder' ? 'Ordner' : 'Datei' }}@if($row->trashed()) · Gelöscht {{ $row->deleted_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}@endif</p>
                        </div>
                        <button type="button" wire:click="select({{ $row->id }})" class="{{ $link }}">Details</button>
                    </div>
                @empty
                    <p class="px-4 py-6 text-sm text-gray-500">Keine Einträge.</p>
                @endforelse
            </div>
            @if($selected)
                <section class="space-y-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="break-words font-bold">{{ $selected->name }}</h2>
                        <button type="button" wire:click="resetSelection" class="{{ $link }}">Schließen</button>
                    </div>
                    @if($selected->trashed())
                        @if($view === 'trash')
                            <div class="flex flex-wrap gap-3">
                                <button type="button" wire:click="restore" wire:loading.attr="disabled" class="{{ $button }}">Wiederherstellen</button>
                                @if($purgeAfter && ! $purgeAfter->isFuture())
                                    <button type="button" wire:click="purge" wire:confirm="Diesen Eintrag und alle enthaltenen Dateien und Versionen endgültig löschen? Dies kann nicht rückgängig gemacht werden." wire:loading.attr="disabled" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white">Endgültig löschen</button>
                                @else
                                    <p class="text-sm text-gray-500">Endgültiges Löschen möglich ab {{ $purgeAfter?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}.</p>
                                @endif
                            </div>
                        @endif
                    @else
                        @if($access->can($user, $selected, 'edit'))
                            <form wire:submit="rename" class="flex flex-wrap items-end gap-2">
                                <label class="text-sm">Name<input wire:model="name" required maxlength="255" class="{{ $input }} ml-2"></label>
                                <button class="{{ $button }}" wire:loading.attr="disabled">Umbenennen</button>
                            </form>
                        @endif
                        @if($access->can($user, $selected, 'manage'))
                            <form wire:submit="move" class="flex flex-wrap items-end gap-2">
                                <label class="text-sm">Zielordner
                                    <select wire:model="destination" class="{{ $input }} ml-2">
                                        @php($selectedSpace = $currentSpace ?? \Extensions\Dateien\Models\Space::query()->find($selected->space_id))
                                        @if($selectedSpace && $access->can($user, $selectedSpace, 'manage'))
                                            <option value="">Bereichs-Stammordner</option>
                                        @else
                                            <option value="" disabled>Ziel auswählen</option>
                                        @endif
                                        @foreach($destinations as $dest)<option value="{{ $dest->id }}">{{ $dest->name }} (#{{ $dest->id }})</option>@endforeach
                                    </select>
                                </label>
                                <button class="{{ $button }}" wire:loading.attr="disabled">Verschieben</button>
                            </form>
                            <button type="button" wire:click="trash" wire:confirm="Diesen Eintrag einschließlich Unterordnern in den Papierkorb verschieben?" wire:loading.attr="disabled" class="text-sm font-medium text-red-700 underline">In den Papierkorb</button>
                        @endif
                        @if($selected->kind === 'file')
                            <h3 class="font-semibold">Versionen</h3>
                            @if($access->can($user, $selected, 'edit'))
                                <form wire:submit="addVersion" class="flex flex-wrap items-end gap-2">
                                    <label class="text-sm">Neue Version<input type="file" wire:model="upload" required class="mt-1 block text-sm"></label>
                                    <button class="{{ $button }}" wire:loading.attr="disabled">Version hochladen</button>
                                </form>
                            @endif
                            <ul class="divide-y divide-gray-100">
                                @forelse($versions as $revision)
                                    <li class="flex flex-wrap items-center gap-3 py-2 text-sm" wire:key="version-{{ $revision->id }}">
                                        <span>Version {{ $revision->number }} · {{ number_format($revision->size / 1024, 1, ',', '.') }} KB · {{ $revision->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                                        <a href="{{ route('dateien.download', ['node' => $selected->id, 'version' => $revision->id]) }}" class="{{ $link }}">Herunterladen</a>
                                        @if($access->can($user, $selected, 'edit'))
                                            <button type="button" wire:click="restoreVersion({{ $revision->id }})" wire:confirm="Diese Version als neue aktuelle Version wiederherstellen?" wire:loading.attr="disabled" class="{{ $link }}">Wiederherstellen</button>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-sm text-gray-500">Keine Versionen.</li>
                                @endforelse
                            </ul>
                        @endif
                    @endif
                </section>
            @endif
            @if($canManage && $view !== 'trash')
                <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h2 class="font-semibold">Berechtigungen: {{ $target->name }}</h2>
                    @if($selected)
                        <form wire:submit="saveInheritance" class="flex flex-wrap items-center gap-3">
                            <label class="text-sm"><input type="checkbox" wire:model="inheritPermissions" class="rounded border-gray-300 text-fw-red"> Berechtigungen vom übergeordneten Ordner erben</label>
                            <button class="{{ $button }}" wire:loading.attr="disabled">Speichern</button>
                        </form>
                    @endif
                    <p class="text-xs text-gray-500">Direkte Freigaben. „Entziehen“ entfernt nur diese direkte Freigabe; geerbte Rechte bleiben bestehen.</p>
                    <ul class="space-y-1 text-sm">
                        @forelse($permissions as $permission)
                            @php
                                $subjectList = $permission->subject_type === 'user' ? $subjects['users'] : $subjects['groups'];
                                $subject = $subjectList->firstWhere('id', $permission->subject_id);
                            @endphp
                            <li class="flex flex-wrap items-center gap-2">
                                <span>{{ $subject?->name ?? 'Nicht verfügbarer Empfänger' }} · {{ ['read' => 'Lesen', 'edit' => 'Bearbeiten', 'manage' => 'Verwalten'][$permission->level] ?? $permission->level }}</span>
                                <button type="button" wire:click="revokePermission({{ $permission->id }})" wire:confirm="Diese direkte Freigabe entziehen?" wire:loading.attr="disabled" class="{{ $link }}">Entziehen</button>
                            </li>
                        @empty
                            <li class="text-gray-500">Keine direkten Freigaben.</li>
                        @endforelse
                    </ul>
                    <form wire:submit="savePermission" class="flex flex-wrap items-end gap-2">
                        <label class="text-sm">Empfängertyp
                            <select wire:model.live="subjectType" class="{{ $input }} mt-1 block"><option value="user">Kamerad</option><option value="group">Dateigruppe</option></select>
                        </label>
                        <label class="text-sm">Empfänger
                            <select wire:model="subjectId" required class="{{ $input }} mt-1 block">
                                <option value="">Bitte auswählen</option>
                                @foreach($subjects[$subjectType === 'user' ? 'users' : 'groups'] as $subject)
                                    <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">Berechtigung
                            <select wire:model="permissionLevel" class="{{ $input }} mt-1 block">
                                <option value="read">Lesen</option><option value="edit">Bearbeiten</option><option value="manage">Verwalten</option><option value="">Entziehen</option>
                            </select>
                        </label>
                        <button class="{{ $button }}" wire:loading.attr="disabled">Übernehmen</button>
                    </form>
                </section>
            @endif
        </main>
    </div>
    <p wire:loading role="status" class="text-sm text-gray-500">Wird verarbeitet …</p>
</div>

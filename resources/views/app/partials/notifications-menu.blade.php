{{--
    The unread number is already in hand — SetCurrentWorkspace counted it with the rest of
    the shell — so the badge is correct on first paint and the component issues no query
    until somebody actually opens the menu.
--}}
@livewire('app.shared.notifications-menu', ['unread' => (int) ($unreadCount ?? 0)])

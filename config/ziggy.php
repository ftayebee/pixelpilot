<?php

/*
| Route names exposed to the browser by @routes. Members only get the routes
| their UI links to; admin and internal endpoints (webhooks, health) are
| only listed for admins, or not at all.
*/

$member = [
    'home',
    'login', 'register', 'logout',
    'password.*',
    'dashboard',
    'generations.*',
    'credits.*',
    'settings.*',
];

return [
    'groups' => [
        'member' => $member,
        'admin' => [...$member, 'admin.*'],
    ],
];

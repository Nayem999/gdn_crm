<?php

return [

    /*
    |-----------------------------------------------------------------
    | Default Prefix
    |-----------------------------------------------------------------
    |
    | This config option allows you to define a default prefix for
    | your icons. The dash separator will be applied automatically
    | to every icon name. It's required and needs to be unique.
    |
    */

    'prefix' => 'lucide',

    /*
    |-----------------------------------------------------------------
    | Fallback Icon
    |-----------------------------------------------------------------
    |
    | This config option allows you to define a fallback
    | icon when an icon in this set cannot be found.
    |
    */

    'fallback' => '',

    /*
    |-----------------------------------------------------------------
    | Default Set Classes
    |-----------------------------------------------------------------
    |
    | This config option allows you to define some classes which
    | will be applied by default to all icons within this set.
    |
    */

    'class' => '',

    /*
    |-----------------------------------------------------------------
    | Default Set Attributes
    |-----------------------------------------------------------------
    |
    | This config option allows you to define some attributes which
    | will be applied by default to all icons within this set.
    |
    */

    // Lucide's SVGs carry no width or height, and blade-icons applies no
    // default, so <x-icon name="lucide-..." /> written without a class
    // collapsed to 0x0 — invisible, and it took its button's width with it.
    //
    // Set as attributes rather than a default class on purpose: blade-icons
    // *appends* a default class to whatever the call site passes, and Tailwind
    // resolves the winner by its own stylesheet order, so a default h-4 would
    // beat an explicit h-3.5. A presentation attribute loses to any CSS rule,
    // so every h-*/w-* at a call site still wins cleanly.
    'attributes' => [
        'width' => 16,
        'height' => 16,
    ],

];

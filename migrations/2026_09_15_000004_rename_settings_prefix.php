<?php

/*
 * This file is part of ygpynet/giveaways.
 *
 * Licensed under the MIT license.
 */

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Container\Container;

// The extension was republished under the ygpynet vendor: all persisted
// settings keys (and the translation namespace they imply) moved from
// `ernestdefoe-giveaways.*` to `ygpynet-giveaways.*`. Rename existing rows so
// upgrading sites keep their configured nav label / show_nav values.
return [
    'up' => function () {
        $settings = Container::getInstance()->make(SettingsRepositoryInterface::class);

        foreach ($settings->all() as $key => $value) {
            if (str_starts_with((string) $key, 'ernestdefoe-giveaways.')) {
                $newKey = 'ygpynet-giveaways.'.substr($key, strlen('ernestdefoe-giveaways.'));
                $settings->delete($key);
                $settings->set($newKey, $value);
            }
        }
    },
    'down' => function () {
        $settings = Container::getInstance()->make(SettingsRepositoryInterface::class);

        foreach ($settings->all() as $key => $value) {
            if (str_starts_with((string) $key, 'ygpynet-giveaways.')) {
                $oldKey = 'ernestdefoe-giveaways.'.substr($key, strlen('ygpynet-giveaways.'));
                $settings->delete($key);
                $settings->set($oldKey, $value);
            }
        }
    },
];

<?php

namespace Mediavine\Create;

// Don't redefine the functions if included multiple times.
if (!\function_exists('Mediavine\\Create\\GuzzleHttp\\Promise\\promise_for')) {
    require __DIR__ . '/functions.php';
}

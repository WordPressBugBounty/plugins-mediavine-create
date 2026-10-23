<?php
// The top-first Ranked variant ('ranked-top') renders through the shared ranked
// template, which detects the layout and leads with the featured #1 (ascending
// 1, 2, 3…) instead of counting down to it. Included in the same view scope so
// $args and the single kses pass in View_Loader::the_view() still apply.
require __DIR__ . '/shortcode-mv-create-list-ranked.php';

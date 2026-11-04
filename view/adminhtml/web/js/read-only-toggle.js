/**
 * Copyright © Mago Assistant
 */
define(['jquery'], function ($) {
    'use strict';

    /**
     * The Read-only server box on the server edit page: while it is ticked, the write and execution
     * tool checkboxes above it are greyed out, so a write is exposed only by unticking read-only first
     * and then ticking that tool. A disabled box is not posted, so the saved list holds reads only.
     *
     * @param {Object} config
     * @param {HTMLElement} element the read-only checkbox
     */
    return function (config, element) {
        var $readOnly = $(element),
            $writable = $('[data-mago-mcp-writable]');

        function apply() {
            $writable.prop('disabled', $readOnly.is(':checked'));
        }

        $readOnly.on('change', apply);
        apply();
    };
});

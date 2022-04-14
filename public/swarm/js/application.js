/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

// define our global variable
var swarm = {};

/**
 * Functions to deal with back and forward buttons resulting in blank pages
 */
/**
 * listener for the react to call and allow legacy code to clean up for react.
 */
window.addEventListener("clearPhpPage", function(event) {
    if (!swarm.isReviewPage()) {
        $('#php-swarm-app').empty();
    }
}, false);
/**
 * listener for the react to call and reload the legacy code if missing.
 */
window.addEventListener("reloadPhpPage", function(event) {
    if ($('#php-swarm-app').children().length === 0) {
        location.reload();
    }
}, false);

/**
 * return true or false if we are currently on an individual review page.
 * @returns {boolean}
 */
swarm.isReviewPage = function() {
    var baseurl = $('body').data('base-url') || "";
    var re      = new RegExp(baseurl + "reviews\/.*[1-9]");
    return re.test(location.pathname);
};

// wrapper around requestAnimationFrame to use a timeout if not present
// requestFrame is to make sure the user isn't blocked by js while performing
// actions like scrolling and resizing
swarm.requestFrame = function(callback) {
    // Use requestAnimationFrame in modern browsers
    // WebkitRequestAnimationFrame in Safari 6
    // and fallback to a timeout in older browsers: Safari 5.1.x and IE9
    if (window.requestAnimationFrame) {
        window.requestAnimationFrame(callback);
    } else if (window.webkitRequestAnimationFrame) {
        window.webkitRequestAnimationFrame(callback);
    } else {
        setTimeout(callback, 1000 / 60);
    }
};

swarm.encodeURIPath = function(path) {
    return encodeURIComponent(path).replace(/\%2F/g, "/");
};

swarm.encodeURIDepotPath = function(depotPath) {
    return swarm.encodeURIPath(depotPath.replace(/^\/*/, ''));
};

// prepend base-url on relative paths
swarm.url = function(url) {
    return url.charAt(0) === '/' ? ($('body').data('base-url') || '') + url : url;
};

// Get the current instance id for multi-server or return 'p4' for single server
swarm.serverId = function() {
    return $('body').data('server-id') || 'p4';
};

// prepend asset-base-url on relative paths
swarm.assetUrl = function(url) {
    return url.charAt(0) === '/' ? ($('body').data('asset-base-url') || '') + url : url;
};

// thin wrapper around local-storage to avoid
// errors if the browser does not support it.
swarm.localStorage = {
    set: function(key, value) {
        if (!swarm.localStorage.canStore()) {
            return null;
        }

        return window.localStorage.setItem(key, value);
    },

    get: function(key) {
        if (!swarm.localStorage.canStore()) {
            return null;
        }

        return window.localStorage.getItem(key);
    },

    canStore: function() {
        try {
            return window.localStorage !== undefined && window.localStorage !== null;
        } catch (e) {
            return false;
        }
    }
};

// This is to load the config from the body. If it exist just return else get config.
swarm.config = {
    globalConfig: [],
    get: function(option){
        if (swarm.config.globalConfig[option] === undefined) {
            swarm.config.globalConfig = $('body').data('config');
        }
        return swarm.config.globalConfig[option];
    }
};

// Convient methods for using native browser querySelector for performance.
// Note that it these depend on a browser's support for the passed selector.
// So these functions should only be used when working with a lot of nodes.
swarm.query = {
    // returns the first matched jquery object, similar to using :first in
    // a $().find but much more performant.
    first: function(selector, element) {
        element = element || document;
        element = element instanceof window.jQuery ? element[0] : element;
        if (element && element.querySelector) {
            return $(element.querySelector(selector));
        }

        return $(selector, element).first();
    },

    // returns all matched jquery objects, just like a regular $().find
    // but much more performant because it uses the browser's native querySelectorAll
    // but also can't support as many selector types
    all: function(selector, element) {
        element = element || document;
        element = element instanceof window.jQuery ? element[0] : element;
        if (element && element.querySelectorAll) {
            return $(element.querySelectorAll(selector));
        }

        return $(selector, element);
    },

    // like jQuery.each but retains the performance of swarm.query.all
    // applies callback to select elements under given root nodes
    apply: function(selector, roots, callback) {
        var i;
        for (i = 0; i < roots.length; i++) {
            swarm.query.all(selector, roots[i]).each(callback);
        }
    }
};

swarm.history = {
    supported:   !!(window.history && window.history.pushState),
    isPageShow:  false,
    initialized: false,

    init: function() {
        // set active tab based on the current url
        // we do this even if history isn't supported, so links load the proper tabs
        swarm.history.switchTab();

        if (swarm.history.initialized) {
            return;
        }

        if (!swarm.history.supported) {
            // for browsers that don't support the history api, change the
            // current url to reflect the tab state when tabs are shown
            var defaultTab = location.hash ? '' : $('.nav-tabs').find('li.active > a[href]').attr('href');
            $(document).on('shown.swarm.tab', '[data-toggle="tab"]', function (e) {
                var href = $(this).attr('href');
                href     = href.substr(href.indexOf('#'));

                // only add history when the new location doesn't match the old hash, or
                // when the old hash is empty, and we are trying to hit the default tab
                if(href && href !== location.hash && !(href === defaultTab && !location.hash)) {
                    location.assign(location.pathname + location.search + href);
                }
            });

            // switch tabs based on hash when navigating history
            $(window).on('hashchange.swarm.history', function() {
                var tab = location.hash || defaultTab;
                swarm.history.switchTab(tab);
            });

            return;
        }

        // push new history state anytime the active tab changes
        $(document).on('shown.swarm.tab', '[data-toggle="tab"]', function (e) {
            var currentTab = window.history.state && window.history.state.tab,
                href       = $(this).attr('href');
            if(href && href !== currentTab) {
                swarm.history.pushState({tab: href}, null, href);
            }
        });

        // switch tabs based on state when navigating history
        swarm.history.onPopState(function(e) {
            e = e.originalEvent;
            if (e.state && e.state.tab && e.state.autoSwitchTab !== false) {
                swarm.history.switchTab(e.state.tab);
            }
        });

        // webkit/blink browser sometimes fire popstate right
        // after pageshow when there is no pending state
        $(window).on('pageshow', function() {
            swarm.history.isPageShow = true;

            // if the browser was going to fire popstate, it would do so right
            // away. It should be safe to reset the pending state
            setTimeout(function() {
                swarm.history.isPageShow = false;
            }, 0);
        });

        // global state defaults for swarm
        $(window).on('beforeSetState', function(e, defaults) {
            $.extend(defaults, {
                tab: $('.nav-tabs').find('li.active > a[href]').attr('href'),
                route: swarm.history.getPageRoute()
            });
        });

        // flag history as initialized and call doStateUpdate to add our default state to the history
        swarm.history.initialized = true;
        swarm.history.doStateUpdate();
    },

    replaceState: function(state, title, url) {
        if (!swarm.history.supported) {
            return;
        }

        var defaults = {};
        $(window).triggerHandler('beforeSetState', [defaults, 'replace']);
        state = $.extend(defaults, state);
        window.history.replaceState(state, title, url);
    },

    pushState: function (state, title, url) {
        if (!swarm.history.supported) {
            return;
        }

        var defaults = {};
        $(window).triggerHandler('beforeSetState', [defaults, 'push']);
        state = $.extend(defaults, state);
        window.history.pushState(state, title, url);
    },

    clearState: function() {
        if (swarm.history.supported) {
            window.history.replaceState(null, null, null);
        }
    },

    doStateUpdate: function() {
        // tickle updating the current state with defaults
        // use this function when you want your default state functions to be
        // re-run and applied to the current state
        if (swarm.history.supported && swarm.history.initialized) {
            swarm.history.replaceState(null, null, null);
        }
    },

    onPopState: function(listener) {
        if (!swarm.history.supported) {
            return;
        }
        $(window).on('popstate', function(e) {
            // some browsers fire a pop when the page first
            // loads which we want to ignore.
            if (swarm.history.isPageShow) {
                return;
            }

            listener.apply(this, arguments);
        });
    },

    getPageRoute: function() {
        var routeMatch = $('body')[0].className.match(/\broute\-(\S+)/i);
        return routeMatch && routeMatch[1];
    },

    switchTab: function(tab) {
        var hash = (
            tab || window.location.hash || $('.nav-tabs').find('li.active > a[href]').attr('href') || ''
        ).replace(/^#/, '');

        // early exit if we don't have a hash, or the hash doesn't match a tab, or the tab is already active
        if (!hash || !$('.nav-tabs a[href="#' + hash + '"]').length
                || $('.nav-tabs li.active a[href="#' + hash + '"]').length) {
            return;
        }

        var element = $('#' + hash + '.fade'),
            active  = element.parent().find('> .fade.active');

        // disable animation
        element.removeClass('fade');
        active.removeClass('fade');

        // show the tab then enable animation
        $('.nav-tabs a[href="#' + hash + '"]').one('shown', function() {
            active.addClass('fade');
            element.addClass('in fade');
        }).tab('show');
    },

    patchPartialSuppport: function() {
        // Add window.history.state property for browsers that support the api,
        // but didn't include access to the current state
        if (!swarm.history.supported || !swarm.has.partialHistorySupport()) {
            return;
        }

        var oldPushState    = window.history.pushState,
            oldReplaceState = window.history.replaceState,
            oldPopState     = window.onpopstate;

        window.history.state = null;
        window.history.pushState = function(state, title, url) {
            window.history.state = state;
            return oldPushState.call(window.history, state, title, url);
        };
        window.history.replaceState = function(state, title, url) {
            window.history.state = state;
            return oldReplaceState.call(window.history, state, title, url);
        };
        window.onpopstate = function(e) {
             window.history.state = e.state;
             if (oldPopState) {
                oldPopState.call(window, e);
             }
        };
    }
};

/*
 * Displays an overlay covering the entire viewport to prevent clicking etc.
 * Intended for use with modal dialogs
 */
swarm.overlay = {
    show: function(spin) {
        if (spin === undefined) {
            spin = true;
        }
        $('#modal-overlay').show();
        if (spin === true) {
            $('#modal-overlay .spinner').show();
        }
    },
    hide: function() {
        $('#modal-overlay').hide();
        $('#modal-overlay .spinner').hide();
    }
};

swarm.modal = {
    show: function(modal) {
        var getMarginLeft =  function() {
            // read the computed margin and adjust if necessary
            return (parseInt($(this).css('marginLeft'), 10) < 0) ? -($(this).width() / 2) : 0;
        };

        // bring in the modal and set it's position
        $(modal).css({width: 'auto', marginLeft: ''}).modal('show').css({marginLeft: getMarginLeft});

        // add resize listener if it doesn't already have one
        if (!$(modal).data('resize-swarm-modal')) {
            var resize = $(window).on('resize', function() {
                $(modal).css('marginLeft', '');
                $(modal).css({marginLeft: getMarginLeft});
            });
            $(modal).data('resize-swarm-modal', resize);
        }
    }
};

swarm.tooltip = {
    showConfirm: function (element, options) {
        // render the popover and show it
        $(element).popover($.extend(
            {
                trigger:   'manual',
                container: 'body',
                placement: 'top'
            },
            options,
            {
                html: true,
                content:
                      '<div class="pad2 padw0 content">'
                    +   (options.content || '')
                    + '</div>'
                    + '<div class="buttons center pad2 padw0">'
                    +   (options.buttons.length ? options.buttons.join('') : '')
                    + '</div>',
                template:
                      '<div class="popover popover-confirm '
                    + (options.classes || '') + '" tabindex="0">'
                    + ' <div class="arrow"></div>'
                    + ' <div class="popover-content center"></div>'
                    + '</div>'
            }
        )).popover('show');

        // set focus on the tooltip
        $(element).data('popover').tip().focus();

        // wire-up closing the confirm when clicked outside
        $(document).mouseup(function (e) {
            if ($('.popover').has(e.target).length === 0) {
                $('.popover-confirm .btn-cancel').click();
                return;
            }
        });
        // return the popover object
        return $(element).data('popover');
    }
};
// General alerts function that could be used a generic code.
swarm.alert = {
    popup: function(message, type){
        var template = $.templates(
            '<div class="swarm-alert alert alert-{{>type}}" id="inner-message">'
                + '<button type="button" class="close" data-dismiss="alert"><span>x</span></button>'
                + '{{>message}}'
            + '</div>'
        ).render({message: message, type: type});
        $('body').append(template);
        swarm.alert.closeIfVisible('#inner-message');
    },
    closeIfVisible: function(target){
        target = $(target);
        // Close the inner-message after 5 seconds.
        if ($('#inner-message').length > 0) {
            $('#inner-message').delay(5000).fadeTo(500, 0, function() {
                $(target).remove();
            });
        }
    }
};
swarm.form = {
    checkInvalid: function(form) {
        var numInvalid = $(form).find('.invalid').length;

        // use the :invalid selector on modern browsers to take advantage of
        // using rules other than just 'required', if :invalid is not available,
        // we will fallback to just looking at required fields
        try {
            numInvalid += $(form).find(':invalid').length;
        } catch (e) {
            numInvalid += $(form).find('[required]:enabled').filter(function() {
                return $(this).val() ? false : true;
            }).length;
        }

        $(form).toggleClass('invalid', numInvalid > 0);
        $(form).find('[type="submit"]').not('.loading').prop('disabled', !!numInvalid);
    },

    post: function(url, form, callback, errorNode, prepareData) {
        swarm.form.rest('POST', url, form, callback, errorNode, prepareData);
    },

    patch: function(url, form, callback, errorNode, prepareData) {
        swarm.form.rest('PATCH', url, form, callback, errorNode, prepareData);
    },

    put: function(url, form, callback, errorNode, prepareData) {
        swarm.form.rest('PUT', url, form, callback, errorNode, prepareData);
    },

    // this cannot be simply 'delete' as it is a reserved word
    restDelete: function(url, form, callback, errorNode, prepareData) {
        swarm.form.rest('DELETE', url, form, callback, errorNode, prepareData);
    },

    rest: function(method, url, form, options, errorNode, prepareData) {
        // Convert options, new style calls, into original parameter vars
        var callback = options,
            errorHandler = null;
        if ($.isPlainObject(options)) {
            callback     = options.callback;
            errorNode    = options.errorNode;
            errorHandler = options.errorHandler;
            prepareData  = options.prepareData;
        }
        var triggerNode = $(form).find('[type="submit"]');
        swarm.form.disableButton(triggerNode);
        swarm.form.clearErrors(form);

        $.ajax(url, {
            type: method||'POST',
            data: prepareData ? prepareData(form) : $(form).serialize(),
            headers: {'X-CSRF-TOKEN':encodeURIComponent($('body').data('csrf')||'')},
            error: (errorNode || errorHandler)
                ? function(jqXhr, status, message) {
                    // If we were given error node, assume that it will display errors
                    this.errorHandled = true;
                    if (errorHandler) {
                        errorHandler(jqXhr, status, message);
                    }
                  }
                : "",
            complete: function(jqXhr, status) {
                swarm.form.enableButton(triggerNode);

                // disable triggers on invalid forms
                if ($(form).is('.invalid')) {
                    triggerNode.prop('disabled', true);
                }

                // call postHandler for successful resquests
                if ((status === 'success' || swarm.ignoredAjaxError) && jqXhr.responseText) {

                    var response = { isValid: false, error: swarm.te(
                            "There is a problem with the Helix Swarm server, please contact your administrator. "
                            + "They will find more details in the Helix Swarm logs.")};
                    try {
                        response = JSON.parse(jqXhr.responseText);
                    } catch (ignore) {
                        // Ignore exceptions, allow default response
                    }
                    swarm.form.postHandler.apply(this, [form, callback, errorNode, response]);
                }
            }
        });
    },
    flattenFieldMessages: function (messages) {
        var flattened = {},
            flattenChildren = function(currentNode, target, flattenedKey) {
                $.each(currentNode, function(key, value) {
                    var newKey = flattenedKey ? flattenedKey + '[' + key + ']' : key;
                    if ($.isPlainObject(value)) {
                        flattenChildren(value, target, newKey);
                    } else {
                        target[newKey] = value;
                    }
                });
            };
        flattenChildren(messages, flattened);
        return flattened;
    },
    postHandler: function(form, callback, errorNode, response) {
        form = $(form);
        if (response.isValid === false || response.error || (response.messages && response.messages.length)) {
            // If we have both an error and details/messages, allow details to have precedence
            var errors   = response.error && ! response.details && $.isEmptyObject(response.messages) ? [response.error] : [],
                event    = $.Event('form-errors');
            event.action = 'error';

            $.each(response.details || swarm.form.flattenFieldMessages(response.messages) || [], function(key, value) {
                var element     = swarm.form.getElement(form, key),
                    controls    = element.closest('.controls'),
                    group       = controls.closest('.control-group');

                group.addClass('error');

                // clear errors on focus
                group.one('focusin.swarm.form.error', function() {
                    swarm.form.clearErrors(this);
                });

                // Allow messages to be a map/array or simple strings
                $.each(typeof value === 'object' ? value : [value], function(errorId, message) {
                    // show the message or add it to a general errors if we can't
                    // locate the corresponding form element to attach it to
                    if (!controls.length) {
                        errors.push(message);
                    } else {
                       $('<span />').text(message).addClass('help-block help-error').appendTo(controls);
                       return false;
                    }
                });
            });

            // show error message and other remaining form error messages
            if (errors.length) {
                errorNode = (errorNode && $(errorNode)) || form;
                errorNode.prepend(
                    $.templates(
                        '<div class="alert">{{for errors}}<div>{{>#data}}</div>{{/for}}</div>'
                    ).render({errors: errors})
                );
                form.one('focusin.swarm.form.error', function(){
                    errorNode.find('> .alert').remove();
                });
            }

            form.trigger(event);
        } else if (response.redirect) {
            // if we are redirecting, ensure the button is disabled
            // we don't want the user posting multiple times
            swarm.form.disableButton($(form).find('[type="submit"]'));
            window.location = response.redirect;
        }

        if (callback) {
            callback(response, form);
        }
    },

    getElement: function(form, key) {
        var parts,
            name = key,
            element = $(form).find('[name="' + name + '"], [name^="'+name+'["]');
        for (parts = (key.match(/\]/g)||[]).length; element.length === 0 && parts > 0; parts--) {
            name    = name.replace(/\[[^\[]*\]$/, '');
            element = $(form).find('[name="' + name + '"], [name^="'+name+'["]');
        }
        return element;
    },

    // takes a form or an input and clears the error markup
    clearErrors: function(element, silent) {
        element = $(element);
        if (!element.is('form')) {
            element = element.closest('.control-group.error');
        }

        element.find('.alert').remove();
        element.find('.help-error').remove();
        element.find('.control-group.error').removeClass('error');
        element.removeClass('error');

        if(!silent) {
            var event    = $.Event('form-errors');
            event.action = 'clear';
            element.trigger(event);
        }
    },

    disableButton: function(button) {
        $(button).prop('disabled', true).addClass('loading');
        var animation = setTimeout(function(){
            $(button).addClass('animate');
        }, 500);
        $(button).data('animationTimeout', animation);
    },

    enableButton: function(button) {
        $(button).prop('disabled', false).removeClass('loading animate');
        clearTimeout($(button).data('animationTimeout'));
    }
};

swarm.about = {
    show: function() {
        if ($('.about-dialog.modal').length) {
            swarm.modal.show('.about-dialog.modal');
            return;
        }

        $.ajax({url: '/about', data: {format: 'partial'}}).done(function(data) {
            $('body').append(data);
            $('.about-dialog .token').click(function(){ $(this).select(); });
        });
    }
};

swarm.info = {
    init: function() {
        // load latest log entries in log tab
        swarm.info.refreshLog();

        // resize iframe on load
        $('iframe').on('load', function(){swarm.info.resizeIframe($(this));});
        $('a[data-toggle="tab"]').on('shown', function(){
            var href = $(this).attr('href');

            // resize the iframe when phpinfo tab is shown (bound to click)
            if (href === '#phpinfo') {
                swarm.info.resizeIframe($('iframe'));
            }

            // hide refresh/download buttons on all tabs but the swarm log
            $('.nav-tabs .btn-group').toggleClass('hidden', href !== '#log');
        });
        $('.btn-refresh').click(function(e) {
            e.preventDefault();
            swarm.info.refreshLog();
        });

        // show download/refresh buttons if the swarm log tab is already open
        if ($('.nav-tabs .active a[href="#log"]').length) {
            $('.nav-tabs .btn-group').removeClass('hidden');
        }
        swarm.info.queueStatus();
        setInterval(function(){
            swarm.info.queueStatus();
        }, 600000);
        $('#startqueueworker').click(function(e) {
            e.preventDefault();
            swarm.overlay.show();
            swarm.info.startWorker();
        });
        $('#startdebugqueueworker').click(function(e) {
            e.preventDefault();
            swarm.overlay.show();
            swarm.info.startWorker(true);
        });
        $('#updatequeue').click(function(e) {
            e.preventDefault();
            swarm.overlay.show();
            swarm.info.queueStatus();
        });
        $('#showqueue').click(function(e) {
            e.preventDefault();
            $('#queueinfo .tasks-list').toggle();
            if ($(this).text() === swarm.te('Show Task Queue')) {
                $(this).text(swarm.te('Hide Task Queue'));
            } else {
                $(this).text(swarm.te('Show Task Queue'));
            }
        });
        //init the cache status data.
        swarm.info.cacheStatus(false);
        $("[id^=verify-]").click(
            function (e) {
                $('#inner-message').remove();
                e.preventDefault();
                swarm.overlay.show();
                swarm.info.cacheVerify(e.target.id.split('-')[1]);
            }
        );
        // refresh status buttons:
        $('#refresh-table').click(
            function (e) {
                $('#inner-message').remove();
                e.preventDefault();
                swarm.overlay.show();
                swarm.info.cacheStatus(true);
            }
        );
        // clear Config buttons:
        $('#clear-config').click(
            function (e) {
                $('#inner-message').remove();
                e.preventDefault();
                swarm.overlay.show();
                swarm.info.clearConfig();
            }
        );
    },

    refreshLog: function() {
        // remove the existing data, and replace with a loading indicator
        var tbody = $('.swarmlog-latest tbody');
        tbody.empty();
        tbody.append(
            '<tr class="loading"><td colspan="3">'
                +  '<span class="loading animate">' + swarm.te('Loading...') + '</span>'
                + '</td></tr>'
        );
        $.ajax({url: '/info/log', data: {format: 'partial'}}).done(function(data) {
            $('.swarmlog-latest tbody').html(data);
            $('.timeago').formatTimestamp();

            // hide/show for entry details, and change chevron icon to point right/down
            $('tr.has-details').click(function(e){
                var details = $(this).next('tr.entry-details');
                $(this).find('.icon-chevron-right').toggleClass('icon-chevron-down');
                details.toggle();
            });
        });
    },

    resizeIframe: function(frame) {
        frame.height((frame.contents().outerHeight()));
    },

    queueStatus: function() {
        var url = '/queue/status';
        $.ajax({url: url}).done(function(data) {
            var tableBody   = $('#queueinfotablebody');
            var startQBtn   = $('#startqueueworker');
            var startDQBtn  = $('#startdebugqueueworker');
            // Empty the please please wait and ensure table is empty.
            $('#queuepleasewait').empty();
            tableBody.empty();
            // For each of the response we should create a table.
            $.each(data, function(title, value) {
                var spaced = title.replace(/([A-Z])/g, ' $1').trim();
                var titled = spaced.toLowerCase().replace(/\b[a-z]/g, function(letter) {
                    return letter.toUpperCase();
                });
                tableBody.append(
                    $('<tr>').append(
                        $('<td>').attr('class', title+'-name').text(titled)
                    ).append(
                        $('<td>').attr('class', title+'-value').text(value)
                    )
                );
            });
            // As we know maxWorkers and workers are always going to be there we do not need to validate them.
            if (data.maxWorkers === data.workers) {
                startQBtn.hide();
                startDQBtn.hide();
            } else {
                startQBtn.show();
                startDQBtn.show();
            }
            swarm.info.getTasks();
            $('#updatequeue').show();
            $('#showqueue').show();
            swarm.overlay.hide();
        });
    },
    startWorker: function(debug) {
        var url = '/queue/worker';
        if (debug === true){
            url = url + '?retire=1';
        }
        $.ajax({url: url}).done(function() {
            setTimeout(
                function() {
                    swarm.info.queueStatus();
                    swarm.overlay.hide();
                },
                2000
            );
        });
    },

    // Call verify for the context requested.
    cacheVerify: function (context) {
        var url = '/api/v9/cache/redis/verify';
        if (context !== 'all') {
            url += '?context=' + context;
        }
        $.ajax({url: url, type: "POST"}).done(
            function () {
                setTimeout(
                    function () {
                        swarm.info.cacheStatus(false);
                        swarm.overlay.hide();
                        swarm.alert.popup(
                            swarm.te('Successfully verified ' + context + ' Redis cache.'),
                            'success'
                        );
                        swarm.alert.closeIfVisible('body');
                    },
                    2000
                );
            }
        );
    },
    // Show the status of the cache verify.
    cacheStatus: function (clicked) {
        var url = '/api/v9/cache/redis/verify';
        $.ajax({url: url}).done(
            function (data) {
                var tableBody = $('#cache-info-table-body');
                // Empty the please please wait and ensure table is empty.
                $('#cache-please-wait').empty();
                tableBody.empty();
                // Setup table titles
                tableBody.append(
                    $('<tr>').append(
                        $('<th>').attr('class', 'context-name').text(swarm.te('Context'))
                    ).append(
                        $('<th>').attr('class', 'state-value').text(swarm.te('State'))
                    ).append(
                        $('<th>').attr('class', 'progress-value').text(swarm.te('Progress'))
                    )
                );
                // Deal with each of the context data.
                $.each(
                    data,
                    function (key, taskValue) {
                        if (typeof taskValue === 'object') {
                            $.each(
                                taskValue,
                                function (dataKey, dataValue) {
                                    var context = dataKey.charAt(0).toUpperCase() + dataKey.slice(1);
                                    var data    = $('<tr>').append(
                                        $('<td>').attr('class', 'context-name').text(context)
                                    );
                                    data.append(
                                        $('<td>').attr('class', 'state-value').text(dataValue.state)
                                    ).append(
                                        $('<td>').attr('class', 'progress-value').text(dataValue.progress)
                                    );
                                    tableBody.append(data);
                                }
                            );
                        }
                    }
                );
                swarm.overlay.hide();
                if (clicked === true) {
                    setTimeout(
                        function () {
                            swarm.alert.popup(
                                swarm.te('Successfully refreshed status.'),
                                'success'
                            );
                            swarm.alert.closeIfVisible('body');
                        },
                        2000
                    );
                }
            }
        );
    },
    clearConfig: function () {
        var url = '/api/v9/cache/config/';
        $.ajax({
            url: url,
            type: "delete",
            headers: {'X-CSRF-TOKEN':encodeURIComponent($('body').data('csrf')||'')},
            success: function(result) {
                setTimeout(
                    function () {
                        swarm.overlay.hide();
                        swarm.alert.popup(
                            swarm.te('Successfully reloaded the config.'),
                            'success'
                        );
                        swarm.alert.closeIfVisible('body');
                    },
                    2000
                );
            },
            error: function(result) {
                swarm.alert.popup(
                    swarm.te('Failed to load config.'),
                    'error'
                );
                swarm.overlay.hide();
                swarm.alert.closeIfVisible('body');
            }
        });
    },
    getTasks: function(type) {
        type    = type || 'allTasks';
        var url = '/queue/tasks';
        $.ajax({url: url, data: {type: type}}).done(function(data) {
            var currentTableBody = $('#taskstablebody'),
                futureTableBody  = $('#futuretaskstablebody');
            currentTableBody.empty();
            futureTableBody.empty();
            if ($.isEmptyObject(data.tasks)){
                currentTableBody.append($('<tr>').text(swarm.te("There are no current or future tasks.")));
            } else {
                $.each(data.tasks.currentTasks, function (title, value) {
                    swarm.info.taskTable(swarm.te("Task"), currentTableBody, title, value);
                });
                $.each(data.tasks.futureTasks, function (title, value) {
                    swarm.info.taskTable(swarm.te("Future task"), futureTableBody, title, value);
                });
            }
        });
    },
    taskTable: function(type, tableBody, title, value) {
        tableBody.append(
            $('<tr>').attr('class', 'title').append(
                $('<td>').text(type +' - ' + title).attr('colspan','100%')
            )
        );
        $.each(value, function (key, taskValue) {
            var titled = key.toLowerCase().replace(/\b[a-z]/g, function (letter) {
                return letter.toUpperCase();
            });
            if (typeof taskValue === 'object') {
                var data = $('<tr>').append(
                    $('<td>').attr('class', 'empty-data').text(titled)
                );
                var dataText = '';
                $.each(Object.values(taskValue), function (dataKey, dataValue){
                    if (typeof dataValue === 'object') {
                        dataText += JSON.stringify(dataValue) + ', ';
                    } else {
                        dataText += dataValue+ ', ';
                    }
                });
                data.append(
                    $('<td>').attr('class', key).text(dataText)
                );
                tableBody.append(data);
            } else {
                // display the time.
                if (key === 'time') {
                    taskValue = new Date(taskValue * 1000);
                }
                tableBody.append(
                    $('<tr>').append(
                        $('<td>').attr('class', 'empty-data').text(titled)
                    ).append(
                        $('<td>').attr('class', key).text(taskValue)
                    )
                );
            }
        });
    }
};

swarm.has = {
    nonStandardResizeControl: function() {
        // webkit uses a custom resize control that eats events
        return !!navigator.userAgent.match(/webkit/i);
    },

    historyAnimation: function() {
        // Safari animates history navigation when using gestures
        return !!(navigator.userAgent.match(/safari/i) && !navigator.userAgent.match(/chrome/i));
    },

    fullFileApi: function() {
        // Sane browsers will offer full support for FormData and File APIs
        return  !(window.FormData === undefined || window.File === undefined);
    },

    _cssCalcSupport: null,
    cssCalcSupport: function() {
        if (swarm.has._cssCalcSupport === null) {
            var testDiv = $(
                '<div />',
                { css: { position: 'absolute', top: '-9999px', left: '-9999px', width: 'calc(10px + 5px)' } }
            ).appendTo('body');
            swarm.has._cssCalcSupport = testDiv[0].offsetWidth !== 0;
            testDiv.remove();
        }

        return swarm.has._cssCalcSupport;
    },

    partialHistorySupport: function() {
        // Safari 5.1.x/Phantom JS support the history API, but don't support accessing the current state
        return !!(window.history && window.history.state === undefined);
    },

    xhrUserAbortAsError: function() {
        // Firefox considers user xhr aborts to be the same as network errors
        return  !!(navigator.userAgent.match(/gecko/i)
            && navigator.userAgent.match(/firefox/i)
            && !navigator.userAgent.match(/webkit/i)
            && !navigator.userAgent.match(/trident/i)
        );
    }
};

swarm.setCookie = function(key, value, expires) {
    document.cookie = key+"="+value+";path="+($('body').data("baseUrl")||"/")+(expires?";Expires="+expires:"");
};

$(function(){

    // check for running workers and Swarm triggers configuration when viewing the home page
    // insert a warning if no workers are running or if we detected problems with Swarm triggers setup
    if ($('body').is('.route-home.authenticated')) {
        $.getJSON('/queue/status', function(data) {
            var warning = '';
            if (data.workers === 0) {
                warning = swarm.te('Hmm... no queue workers? Ask your administrator to check the')
                    + ' <a href="' + swarm.assetUrl('/docs/Content/Swarm/setup.worker.html') + '">' + swarm.te("worker setup") + '</a>.';
            } else if (data.pingError) {
                warning = $.templates(
                      '{{te:"Swarm triggers not working?"}} '
                    + '{{if error !== true}}<div class="ping-error">{{>error}}</div>{{/if}}'
                    + '{{te:"Ask your administrator to check the"}} <a href="{{:url}}">{{te:"triggers setup"}}</a>.'
                ).render({error: data.pingError, url: swarm.assetUrl('/docs/Content/Swarm/setup.perforce.html#setup.perforce.triggers')});
            }

            if (warning) {
                $('body > .container-fluid').first().prepend(
                    '<div class="alert alert-error center">' + warning + '</div>'
                );

                $('.ping-error').expander({slicePoint: 80});
            }
        });
    }

    // This is to fade out the alert message when a project or group is saved or cancelled.
    $(document).ready (function() {
        // Close the inner-message after 5 seconds.
        swarm.alert.closeIfVisible('#message');
        $(document).keydown(function(e){
            if ($('#modal-overlay').is(":visible")) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
            }
        });
        // detect it's IE11 to add hashchange listener
        if (document.documentElement.style.hasOwnProperty('-ms-scroll-limit') && document.documentElement.style.hasOwnProperty('-ms-ime-align')) {
            window.addEventListener("hashchange", function(event) {
                var currentPath = window.location.hash.slice(1);
                swarm.history.switchTab(currentPath);
            }, false);
        } else {
            // If the browser detects the URL has changed then events fire to switch tabs, however
            // if the URL doesn't appear different the hashchange fires instead and we can still
            // switch.
            $(window).on('hashchange', function (e) {
                var newURL = e.originalEvent.newURL;
                var newHash = newURL.split("#")[1];
                swarm.history.switchTab(newHash);
            });
        }
    });
});

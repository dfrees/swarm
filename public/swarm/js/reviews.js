/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

$.expr.pseudos.dataLabelContains = $.expr.createPseudo(function(arg) {
    return function( elem ) {
        return String($(elem).data('short-label')).toLowerCase().indexOf(arg.toLowerCase()) >= 0;
    };
});

swarm.reviews = {

    // Effective static definition of msgIds to be picked up by
    // i18n generation. Never needs to be called
    msgIds: function() {
        swarm.te('Reviewers');
        swarm.te('Up Votes');
        swarm.te('Down Votes');
        swarm.te('Edit Reviewers');
        swarm.te('Reviewer Name');
        swarm.te('Edit Reviewers');
        swarm.te('Make Vote Optional');
        swarm.te('Make Vote Required');
        swarm.te('Make One Vote Required');
        swarm.te('Make All Votes Required');
        swarm.te('Close');
        swarm.te('Already Committed');
        swarm.te('Update');
        swarm.te('Withdraw group from review');
        swarm.te('Exempt (review owner)');
        swarm.te('Make my Vote Optional');
        swarm.te('Make my Vote Required');
        swarm.te('Leave Review');
        swarm.te('Vote Up');
        swarm.te('Vote Down');
        swarm.te('Clear Vote');
        swarm.te('Disable Notifications');
        swarm.te('Enable Notifications');
        swarm.te('Join Review');
        swarm.te('Pre-commit');
        swarm.te('Post-commit');
    },

    init: function() {
        // refresh tab panes (active first)
        var active  = $('.reviews .tab-pane.active'),
            filters = $.deparam(location.search, true);

        swarm.reviews.setFilters(filters, active);
        swarm.reviews.load(active, true);
        $('.reviews .tab-pane').not(active).each(function() {
            swarm.reviews.setFilters(filters, this);
            swarm.reviews.load(this, true);
        });

        // listen to filter buttons onclick events
        $('.reviews .btn-filter').on('click.reviews.filter', function(e) {
            e.preventDefault();
            swarm.reviews.toggleFilter(this, true);
        });

        swarm.reviews.initMultiPicker();

        // continuously add reviews when scrolling to bottom
        $(window).scroll(function() {
            if ($.isScrolledToBottom()) {
                var tabPane = $('.reviews .tab-pane.active');
                var table =  tabPane.find('table');
                // Only add more if there are less row in the table than the badge count
                var expected = parseInt($('.reviews .' + tabPane.attr('id') + '-counter').text(), 10)||0;
                var loaded   = table.find('tbody tr').length;
                if (expected > loaded){
                    table.data('end-of-data', null).data('last-seen', table.data('last-filtered')||table.data('last-seen'));
                    table.find('span.little-bee').width(14);
                    swarm.reviews.load($('.reviews .tab-pane.active'));
                }
            }
        });

        // wire-up search filter
        var events = ['input', 'keyup', 'blur'];
        $('.reviews .toolbar .search input').on(
            events.map(function(e){ return e + '.reviews.search'; }).join(' '),
            function(event){
                // apply delayed search
                var tabPane = $(this).closest('.tab-pane');
                clearTimeout(swarm.reviews.searchTimeout);
                swarm.reviews.searchTimeout = setTimeout(function(){
                    if ($(event.target).val() !== (tabPane.data('last-search') || '')) {
                        swarm.reviews.applyFilters(tabPane);
                        tabPane.data('last-search', $(event.target).val());
                    }
                }, 500);
            }
        );


        // update review panes when user logs in
        $(document).on('swarm-login', function () {
            swarm.reviews.load(active, true);
            $('.reviews .tab-pane').not(active).each(function() {
                swarm.reviews.load(this, true);
            });
        });
    },

    initMultiPicker: function () {
        // prevent closing when clicking on filter input
        $('.dropdown-menu').on('click', '.input-filter', function(e) {
            e.stopPropagation();
        });

        // ensure multipicker is only initialized when the user is logged in
        // this prevents an HTTP 401 error when populating the typeahead list
        if (!$('body').hasClass('authenticated')) {
            $(document).on('swarm-login', function () {
                swarm.reviews.initMultiPicker();
            });

            return;
        }


        // adding userMultiPicker plugin for filtering
        $('.btn-user .user-filter .input-filter').each(function() {
            var $input = $(this),
                group = $input.closest('.btn-group'),
                dropdown = group.find('.dropdown-toggle');

            // hide clear icon if input is empty
            $input.on('change', function () {
                $input.siblings('.clear').hide();
                if ($input.val()) {
                    $input.siblings('.clear').show();
                }
            }).change();

            $input.siblings('.clear').on('click', function (e) {
                $input.val('').data('filter-value', '').change();
                e.stopPropagation();
            });

            // handling 'enter' and 'esc' keys
            $input.on('keydown', function(e) {
                if ($(this).data('user-multipicker').typeahead.$menu.find('.active:visible').length) {
                    return true;
                }

                if(e.keyCode === 13) {
                    $(document).trigger('click.dropdown.data-api');
                    e.preventDefault();
                } else if(e.keyCode === 27) {
                    $input.val('');
                    $(document).trigger('click.dropdown.data-api');
                    e.preventDefault();
                }
            });

            // toggle filter on dropdown close
            dropdown.on('dropdown-close', function(){
                if (!$input.val() && group.find('.btn-filter.active').length) {
                    return false;
                }

                // if input is empty set filter value to groups default
                swarm.reviews.toggleFilter(!$input.val() ? group.find('.default') : $input, true);
            });


            $input.userMultiPicker({
                onSelect: function() {
                    var active = this.typeahead.$menu.find('.active');
                    if (active.length) {
                        $input.val(active.data('value').id);
                    }
                    $(document).trigger('click.dropdown.data-api');
                }
            });
        });
    },

    addEventsToProjectDropdown: function(){
        function clearProjectListStyling(list) {
            list.find("a.btn-filter").each(function() {
                this.innerHTML=$(this).data('short-label');
                $(this).show();
            });
        }

        function encodeID(str) {
            if (str) {
                str = str.replace( /(:|\.|\[|\]|,|=|@)/g, "\\$1" );
            }
            return str;
        }

        // wire up filtering projects
        $('.btn-project .project-filter .input-filter').each(function() {
            var $input = $(this),
                group = $input.closest('.btn-group'),
                dropdown = group.find('.dropdown-toggle'),
                list = group.find('.dropdown-menu li.list-item');

            $input.on('input change', function () {
                $input.siblings('.clear').hide();

                if (!$input.val()) {
                    // if value is empty - show all and clear styling
                    clearProjectListStyling(list, group);
                } else {
                    $input.siblings('.clear').show();
                    list.find("a.btn-filter").each(function() {
                        $(this).hide();
                    });
                    list.find("a.btn-filter:dataLabelContains('" + $input.val() + "')").each(function() {
                        var projectName = $(this).data('short-label');
                        var reg = new RegExp($input.val(), 'gi');
                        this.innerHTML=projectName.replace(reg, "<b>$&</b>");
                        $(this).show();
                    });
                }

            }).change();

            $input.siblings('.clear').on('click', function (e) {
                $input.val('').data('filter-value', '').change();
                e.stopPropagation();
                clearProjectListStyling(list, group);
            });

            // handling 'enter' and 'esc' keys and arrow-down event on the input box
            $input.on('keydown', function(e) {
                var listItems = group.find('.dropdown-menu');
                if(e.keyCode === 13) {
                    localStorage.setItem('swarm.project.filter', $(this).parent().attr('id'));
                    $(document).trigger('click.dropdown.data-api');
                    e.preventDefault();
                } else if(e.keyCode === 27) {
                    $input.val('');
                    $(document).trigger('click.dropdown.data-api');
                    e.preventDefault();
                } else if (e.keyCode === 40) {
                    e.stopImmediatePropagation();

                    group.find('ul.dropdown-menu').children().each(function(){
                        $(this).removeClass('active');
                    });
                    var itemID = encodeID(listItems.find('li.list-item a:visible').first().closest('li').attr('id'));
                    $('#' + itemID).find('a').focus().addClass('active');
                }

            });

            // wire up navigation - as default navigation doesnt like hidden elements we need to find visible ones ourselves
            group.find('ul.dropdown-menu').on('keydown', function(e){
                if (e.keyCode === 40) {
                    e.stopImmediatePropagation();
                    e.preventDefault();
                    // find current visible element and find id of a next visible element
                    var nextElementID = encodeID($(document.activeElement).closest('li').nextAll('li').find('a:visible,input').first().closest('li').attr('id'));
                    if (nextElementID) {
                        $(document.activeElement).removeClass('active');
                        $('#' + nextElementID).find('a,input').focus().addClass('active');
                    }

                } else if (e.keyCode === 38) {
                    e.stopImmediatePropagation();
                    e.preventDefault();
                    // find current visible element and find id of a previous visible element
                    var previousElementID = encodeID($(document.activeElement).closest('li').prevAll('li').children('a:visible,input').first().closest('li').attr('id'));
                    if (previousElementID) {
                        $(document.activeElement).removeClass('active');
                        $('#' + previousElementID).find('a,input').focus().addClass('active');
                    } else {
                        // if we have not found an element at this level - try to go one higher
                        previousElementID = encodeID($(document.activeElement).closest('ul').prevAll('li').children('a:visible,input').first().closest('li').attr('id'));
                        $(document.activeElement).removeClass('active');
                        $('#' + previousElementID).find('a,input').focus().addClass('active');
                    }
                }
            });

            // toggle filter on dropdown click
            group.find('.dropdown-menu li:has(> a) a').on('click', function(){
                localStorage.setItem('swarm.project.filter', $(this).parent().attr('id'));
            });

            // clear selection on dropdown close
            dropdown.on('dropdown-close', function(){
                clearProjectListStyling(list, group);
                if (localStorage.getItem('swarm.project.filter') !== 'my-projects') {
                    $input.val($("#" + encodeID(localStorage.getItem('swarm.project.filter')) + " a").data('filter-value')).change();
                    // if input is empty set filter value to groups default
                    swarm.reviews.toggleFilter(!$input.val() ? group.find('.default') : $("#" + encodeID(localStorage.getItem('swarm.project.filter')) + " a"), false);
                }
                else {
                    swarm.reviews.toggleFilter(group.find("#my-projects a"), false);
                }
            });
        });
    },

    load: function(tabPane, reset, deficit) {
        tabPane = $(tabPane);
        if (tabPane.data('loading')) {
            if (!reset) {
                return;
            }

            tabPane.data('loading').abort();
            tabPane.data('loading', false);
        }

        var table = $(tabPane).find('.reviews-table');

        // clean the table if reset
        if (reset) {
            table.data('last-seen', null);
            table.data('last-filtered', null);
            table.data('end-of-data', null);
            table.data('filtered-total', null);
            table.data('end-of-filtered-data', null);
            table.removeData('after-updated');
            table.find('tbody').empty();
            table.find('tfoot .little-bee').width(14);
            $('.closed-counter, .opened-counter').text('0');
        }

        // if there are no more review records, nothing else to do
        if (table.data('end-of-data')) {
            table.data('iterations',0);
            table.find('tfoot').hide();
            return;
        }

        // Enable a progress bar
        table.find('tfoot:hidden').show();
        swarm.reviews.reportProgress(tabPane);

        var _loading = $.ajax({
                url:        location.pathname,
                data:       $.extend(swarm.reviews.getFilters(tabPane), {
                    format: 'json',
                    after:  table.data('last-seen'),
                    afterSorted: table.data('last-sorted'),
                    afterUpdated: table.data('after-updated')
                }),
                dataType:   'json',
                skipBaseUrl: true,
                success:    function(data){
                    var max = data.max || 50;
                    // if the last-seen id we received is null or same as the one from previous request,
                    // set 'end-of-data' to indicate there are no more reviews to fetch
                    // Only go into this if we are not on last updated.
                    if (table.is('.updated-order') === false){
                        // Not sorting by last activity, stop when no more data or last seen is unchanged
                        if (data.lastSeen === null || data.lastSeen === table.data('last-seen')) {
                            // End of data reached
                            table.data('end-of-data', true);
                            table.data('end-of-filtered-data', true);
                        }
                    } else {
                        if ( deficit <= 0 || data.reviews.length + table.find('tr').length > data.totalCount ) {
                            // End of data must have been reached, when the size of the table matches the badge
                            table.data('end-of-data', true);
                        }
                        // We check that the last seen value has been set meaning we don't re-fetch after the returning
                        // the results, preventing the endless ajax calls.
                        if((table.data('last-seen') && table.data('end-of-data') === null
                            && table.data('filtered-total') === null && table.data('end-of-filtered-data') === null)
                            || data.afterUpdated <= 0) {
                            table.data('end-of-data', true);
                        }
                    }
                    table.data('last-seen', data.lastSeen);
                    table.data('after-updated', data.afterUpdated);
                    table.data('last-sorted', data.lastSorted);
                    table.data('iterations', 1+(table.data('iterations')||0));
                    // render rows from received data and append them to the table
                    $.each(swarm.reviews.sort(data.reviews,table.is('.updated-order')?"updated":"created"), function(key, reviewData){
                        
                        // Render if not full, look at decrementing deficit and keeping track of last sorted
                        if (deficit > 0 || deficit === undefined) {
                            var row = $.templates(
                                '<tr data-id="{{>id}}" data-pending="{{>pending}}"class="state-{{>state}}">'
                                + ' <td class="id"><a href="{{url:"/reviews"}}/{{urlc:id}}">{{>id}}</a></td>'
                                + ' <td class="author center">{{:authorAvatar}}</td>'
                                + ' <td class="description">{{:description}}</td>'
                                + ' <td class="project-branch">{{:projects}}</td>'
                                + ' <td class="created"><span class="timeago" title="{{>createDate}}"></span></td>'
                                + ' <td class="updated"><span class="timeago" title="{{>updateDate}}"></span></td>'
                                + ' <td class="state center">'
                                + '  <a href="{{url:"/reviews"}}/{{urlc:id}}"><i class="swarm-icon icon-review-{{>state}}" title="{{te:stateLabel}}"></i></a>'
                                + ' </td>'
                                + '<td class="type center">'
                                + '<i class="swarm-icon {{if pending}}icon-pre-review" title="{{te:"Pre-commit"}}"{{else}}icon-post-review" title="{{te:"Post-commit"}}"{{/if}}></i>'
                                + '</td>'
                                + ' <td class="test-status center">'
                                + '  {{if testStatus == "pass"}}{{if testDetails.url}}<a href="{{url:testDetails.url}}" target="_blank">{{/if}}'
                                + '  <i class="icon-check" title="{{te:"Tests Pass"}}"></i>{{if testDetails.url}}</a>{{/if}}'
                                + '  {{else testStatus == "fail"}}{{if testDetails.url}}<a href="{{url:testDetails.url}}" target="_blank">{{/if}}'
                                + '  <i class="icon-warning-sign" title="{{te:"Tests Fail"}}"></i>{{if testDetails.url}}</a>{{/if}}'
                                + '  {{else testStatus == "running" || testStatus == "started"}}{{if testDetails.url}}<a href="{{url:testDetails.url}}" target="_blank">{{/if}}'
                                + '  <i class="icon-svg-refresh animate" title="{{te:"Tests Running"}}"></i>{{if testDetails.url}}</a>{{/if}}'
                                + '  {{/if}}'
                                + ' </td>'
                                + ' <td class="comments center">'
                                + '  <a href="{{url:"/reviews"}}/{{urlc:id}}#comments" {{if comments[1]}}title="{{tpe:"%s archived" "" comments[1]}}"{{/if}}>'
                                + '   <span class="badge {{if !comments[0]}}muted{{/if}}">{{>comments[0]}}</span>'
                                + '  </a>'
                                + ' </td>'
                                + ' <td class="votes center">'
                                + '  <a href="{{url:"/reviews"}}/{{urlc:id}}">'
                                + '   <span class="badge {{if !upVotes.length && !downVotes.length}}muted{{/if}}">'
                                + '    {{>upVotes.length}} / {{>downVotes.length}}'
                                + '   </span>'
                                + '  </a>'
                                + ' </td>'
                                + '</tr>'
                            ).render(reviewData);
                            $(row).appendTo(table.find('tbody')).find('td.description').expander({slicePoint: 90});
                            table.data('last-filtered', reviewData.id);
                            if ( 0 === reviewData.id % 99) {
                                table.data('progress', reviewData.id);
                            }
                        }

                        // Set the table last seen element to the minimum item displayed, not for last activity??
                        if ( !table.is('.updated-order') && reviewData.id <= table.data('last-seen') ) {
                            table.data('last-seen', reviewData.id);
                        }
                        // Set the table after updated element to the oldest item displayed
                        if ( reviewData.updated >= table.data('after-updated')) {
                            table.data('after-updated', reviewData.updated);

                        }
                    });

                    // For postFiltered data, keep track of totalMatched
                    if(data.postFiltered && !table.data('end-of-filtered-data')) {
                        table.data('filtered-total', (table.data('filtered-total')||0) + data.reviews.length);
                    }

                    // update tab counter
                    $('.reviews .' + tabPane.attr('id') + '-counter').removeClass('animate').text(
                        ! data.postFiltered ? data.totalCount : (table.data('filtered-total')||0));

                    // convert times to time-ago
                    table.find('.timeago').formatTimestamp();

                    // if we have no reviews to show and there are no more on the server, let the user know
                    if (!table.find('tbody tr').length && table.data('end-of-data')) {
                        var message = swarm.te(
                            $(tabPane).find('.btn-filter.active').not('.default').length
                                ? 'No ' + $(tabPane).attr('id') + ' reviews match your filters.'
                                : 'No ' + $(tabPane).attr('id') + ' reviews.'
                        );
                        if(table.is('.updated-order') === true){
                            message = message + swarm.te(
                                ' If you are expecting reviews here maybe they have not been indexed yet!'
                            );
                        }

                        $('<tr class="reviews-info">'
                            + ' <td colspan="' + table.find('thead th').length + '">'
                            + '  <div class="alert border-box pad3">' + message + '</div>'
                            + ' </td>'
                            + '</tr>'
                        ).appendTo(table.find('tbody'));
                    }

                    // load again if we get less than half the results we asked for
                    // or the results don't fill the page (e.g. due to change/project filtering)
                    deficit = (deficit === undefined ? max : deficit) - data.reviews.length;

                    // compute table height - table could be in a closed tab
                    var height = table.height();
                    // Due to swap function not being documented and removed in latest jquery, we have replace the
                    // function with the code it ran. This is checking the height of the non visible tab and ensuring
                    // it has more rows than the screen size.
                    if(!height) {
                        var name,
                            oldValues = {},
                            newValues = {display: 'block', visibility: 'hidden'};
                        // Remember the oldValues, and insert the newValues.
                        for ( name in newValues ) {
                            if (newValues.hasOwnProperty(name)) {
                                oldValues[name] = tabPane[0].style[name];
                                tabPane[0].style[name] = newValues[name];
                            }
                        }
                        // Get the height of the hidden tab with new populated data
                        height = table.height();
                        // Revert back to the oldValues
                        for ( name in oldValues ) {
                            if (oldValues.hasOwnProperty(name)) {
                                tabPane[0].style[ name ] = oldValues[ name ];
                            }
                        }
                    }
                    // If the height of the table is less than screen load more records.
                    if (deficit > Math.round(max / 2) || (deficit > 0 && height && height < $(window).height()) || data.postFiltered) {
                        tabPane.data('loading', false);
                        return swarm.reviews.load(tabPane, false, deficit);
                    }

                    // There is no more data to be loaded, so get rid of the Processing... footer
                    table.find('tfoot').hide();

                    // enforce a minimal delay between requests
                    setTimeout(function() {
                        if (tabPane.data('loading') === _loading) {
                            tabPane.data('loading', false);
                        }
                    }, 500);
                }
            });
        tabPane.data('loading', _loading);
    },

    reportProgress: function(location){
        var textWidth = $('.active tfoot th .message').width();
        var progressWidth = $('span.little-bee').width();
        var cellWidth = $('.active tfoot th').width();
        var newWidth = progressWidth+((cellWidth-textWidth-progressWidth)*0.005);

        // Schedule next update, for the currently active pane, both panes will show progress
        if($('.active .loading span.little-bee:visible').length &&
            (location === undefined || location.hasClass('active'))) {
            $('.loading span.little-bee').width(newWidth);
            setTimeout(swarm.reviews.reportProgress, 2000);
        }
    },

    escapeID: function(str) {
        return str.replace( /(:|\.|\[|\]|,|=|@)/g, "\\$1" );
    },

    initProjectList: function(project, projects, myProjects) {
        // init top level divs
        var projectFilter    = $('.btn-group.btn-project.group-radio'),
            dropdownTitle    = swarm.te(project ? 'All' : 'All Projects'),
            myProjectsText   = swarm.te('My Projects'),
            dropdownMenu     = $("<ul class='dropdown-menu'>").attr('aria-label', dropdownTitle),
            dropdownTypeText = swarm.te(project ? 'Branch' : 'Project');

        // add the button
        var button = $('<button type="button" class="btn btn-project dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" ' + dropdownTypeText + '></button>');
        button.append($("<i class='" + project ? 'icon-branch' : 'icon-project' + " swarm-icon'></i>"));

        // add all-projects and my-project list items
        dropdownMenu.append("<li id='all-projects'><a href='#' class='btn-filter default' data-filter-value=''>" + dropdownTitle + "</a></li>");
        if (myProjects.length > 0) {
            var user = String(swarm.user.getAuthenticatedUser());
            var li = $("<li id='my-projects'></li>");
            if (user) {
                var userId = user.id;
                li.append($("<a data-filter-value='projects-for-user:" + userId + "'></a>").addClass('btn-filter').text(myProjectsText));
            } else {
                li.append($("<a data-filter-value='" + JSON.stringify(myProjects) + "'></a>").addClass('btn-filter').text(myProjectsText));
            }
            dropdownMenu.append(li);
        }

        // add the input field
        dropdownMenu.append('<li class="divider"></li>');
        var projectFilterInput = $('<li></li>').addClass('project-filter');
        projectFilterInput.append('<input class="input-filter" data-filter-key="project" type="text" placeholder=' + dropdownTypeText + '>');
        projectFilterInput.append('<button type="button" class="clear"><span>x</span></button>');
        dropdownMenu.append(projectFilterInput);
        dropdownMenu.append('<li class="divider"></li>');

        var ul = $("<ul class='dropdown-menu'></ul>");

        // add li items for all projects from myProjects list
        $.each(projects, function(index, value) {
            var inner_li = $('<li class="list-item" id="' +  index  + '"></li>');
            inner_li.append($('<a href="#" class="btn-filter btn-filter-project" data-filter-key="project" data-filter-value="' +  index + '" data-short-label="' + value + '">' + value + '</a>'));
            ul.append(inner_li);
        });
        if (projects) {
            dropdownMenu.append(ul);
        }

        projectFilter.append(dropdownMenu);

        swarm.reviews.addEventsToProjectDropdown();

    },

    toggleFilter: function(control, applyFilter) {
        // buttons toggle on and off when clicked
        // items in drop-downs don't toggle - they stay active when clicked and clearing sibling input filters
        // if in drop-down, set button label to match the selected item
        if (!$(control).closest('.dropdown-menu').length) {
            $(control).toggleClass('active');
        } else if ($(control).is('input')) {
            $(control).data('filter-value', $(control).val());
            $(control).closest('.btn-group').find('.text').text($(control).val());
        } else {
            $(control).addClass('active');
            $(control).closest('.btn-group').find('.text').text($(control).data('short-label') || $(control).html());

            // clear sibling input filters if this control is part of a btn-radio group
            $(control).closest('.btn-group.group-radio').find('.input-filter').siblings('.clear').trigger('click');
        }

        // De-activate anything that is no longer allowed to be selected
        var group = $(control).closest('.btn-group.group-radio');
        if ( group.hasClass('multi-select')){
            // force no selection when all multi-select options are toggled on
            if (0 === group.find('.btn-filter').not('.active').length) {
                group.find('.btn-filter').removeClass('active').blur();
            }
        } else {
            // deactivate other controls if inside btn-radio group
            group.find('.btn-filter').not(control).removeClass('active');
        }

        // process order by values
        if ($(control).hasClass('btn-sort')) {
            $('.'+$(control).data('target')).removeClass(function (index, className) {
                return (className.match (/[^\s]+-order/g)||[]).join(' ');
            }).addClass(($(control).filter('.active').data('filter-value')||'default') + '-order');
        }
        // apply the new filter
        if (applyFilter) {
            swarm.reviews.applyFilters($(control).closest('.tab-pane'));
        }
    },

    applyFilters: function(tabPane) {
        var filters = swarm.reviews.getFilters(tabPane);

        // if the state filter contains all the tab states, then it is trying to apply the
        // default filtering rules and we can drop the state filter from the url
        if (filters.state && filters.state.length === swarm.reviews.getAllTabStates(tabPane).length) {
            delete filters.state;
        }

        // only use state content that comes before a colon
        if (filters.state && filters.state.indexOf(':') !== -1) {
            filters.state = filters.state.split(':')[0];
        }

        // update the url to expose the current filters
        var params = $.isEmptyObject(filters) ? '' : '?' + $.param(filters),
            hash   = '#' + encodeURIComponent(tabPane[0].id);
        swarm.history.replaceState(null, null, location.pathname + params + hash);


        // refresh the tab panes, the applied one first
        swarm.reviews.load(tabPane, true);
        $('.reviews .tab-pane').not(tabPane).each(function() {
            swarm.reviews.setFilters(filters, this);
            swarm.reviews.load(this, true);
        });
    },

    getFilters: function(tabPane) {
        var filters = {};

        // get active filters from toolbar buttons
        $(tabPane).find('.btn-filter.active, .input-filter:not(.input-project-filter)').each(function(){
            var control      = $(this),
                group       = control.closest('.btn-group'),
                filterKey   = control.data('filter-key') || group.data('filter-key'),
                filterValue = control.data('filter-value');

            if (filterKey && filterValue !== '' && filterValue !== undefined) {
                if (filters.hasOwnProperty(filterKey)) {
                    if (!$.isArray(filters[filterKey])) {
                        filters[filterKey] = [filters[filterKey]];
                    }
                    filters[filterKey].push(filterValue);
                } else {
                    filters[filterKey] = filterValue;
                }
            }
        });

        // if no states are active, behave as if all states are active
        if (!filters.state) {
            filters.state = swarm.reviews.getAllTabStates(tabPane);
        }

        // add keyword value to the filter
        var keywords = $(tabPane).find('.toolbar .search input').val();
        if (keywords) {
            filters.keywords = keywords;
        }

        return filters;
    },

    getAllTabStates: function(tabPane) {
        var states = [];
        $(tabPane).find('.toolbar [data-filter-key=state] button').each(function() {
            states.push($(this).data('filter-value'));
        });
        return states;
    },

    setFilters: function(filters, tabPane) {
        var author, input, project;


        filters = $.extend({}, filters);

        // if filter.state contains all of tab state filters, drop them
        // from the filter, because the default behavior is being used
        if ($.isArray(filters.state)) {
            var tabStates  = swarm.reviews.getAllTabStates(tabPane);
            var stateUnion = $.grep(filters.state, function(value) {
                return $.inArray(value, tabStates) !== -1;
            });
            if (stateUnion.length === tabStates.length) {
                delete filters.state;
            }
        }

        // set toolbar buttons from filters
        $(tabPane).find('.btn-group[data-filter-key]').each(function() {
            var group     = $(this),
                filterKey = group.data('filter-key'), button;

            // clear the active flag to reset the button group
            group.find('.btn-filter.active').removeClass('active');

            // activate default button if filter does not include the key
            // else activate the button with the matching value
            if (!filters.hasOwnProperty(filterKey)) {
                button = group.find('.btn-filter.default');
            } else {
                var filterValue = String(filters[filterKey]);
                button = group.find('.btn-filter').filter(function() {
                    // we need to check if current value is an array or not
                    var isArray = $.isArray($(this).data('filter-value'));
                    // for the state type, where states can be combined using a colon,
                    // we consider the button a match if the first state matches
                    var buttonValue = String($(this).data('filter-value'));
                    if (filterKey === 'state') {
                        buttonValue = buttonValue.split(':')[0];
                        filterValue = filterValue.split(':')[0];
                        // As state can have multiple selected we need to check each value.
                        if ($.isArray(filterValue.split(','))) {
                            if ($.inArray(buttonValue, filterValue.split(',')) !== -1) {
                                return true;
                            }
                        }
                    }

                    // projects can be an array as well

                    return buttonValue === filterValue
                        && ((!isArray && filterKey !== 'state') || (isArray && filterKey === 'state'));
                });
            }

            // active button but don't apply changes yet
            if (button.length) {
                swarm.reviews.toggleFilter(button, false);
            }
        });

        // apply special handling to the 'User' toolbar button
        // the key is on the individual options, not the group, so the above logic bypasses it
        swarm.reviews.toggleFilter($(tabPane).find('.btn-user a.btn-filter.default'), false);
        if (filters.author) {
            author = $(tabPane).find('.btn-user a[data-filter-key=author]');
            input = $(tabPane).find('.btn-user.btn-group').find('.input-filter');
            if(author.data('filter-value') === filters.author) {
                swarm.reviews.toggleFilter(author, false);
            } else {
                input.val(filters.author).change();
                swarm.reviews.toggleFilter(input, false);
            }
        }
        if (filters.participants) {
            swarm.reviews.toggleFilter($(tabPane).find('.btn-user a[data-filter-key=participants]'), false);
        }
        if (filters.authorparticipants) {
            swarm.reviews.toggleFilter($(tabPane).find('.btn-user a[data-filter-key=authorparticipants]'), false);
        }
        // swarm.reviews.toggleFilter($('.tab-pane').find('.btn-project a.btn-filter.default'), false);
        if (filters.project) {
            var myProjects = false;
            // check if the url filter is the same as the one in localStorage
            if (swarm.reviews.isMyProjectsFilter(filters.project)) {
                // my projects
                project = $("#my-projects a");
                myProjects = true;
            } else {
                if (localStorage.getItem('swarm.project.filter') === filters.project) {
                    project = $("#" + this.escapeID(localStorage.getItem('swarm.project.filter')) + " a");
                } else {
                    // url filter takes precedence, update localstorage
                    localStorage.setItem('swarm.project.filter', filters.project);
                }
            }
            input = $('.tab-pane').find('.btn-group.btn-project').find('.input-filter:not(.input-project-filter)');
            if (!myProjects) {
                if(project) {
                    input.val(project.data('filter-value')).change();
                    swarm.reviews.toggleFilter(project, false);
                } else {
                    input.val(filters.project).change();
                    swarm.reviews.toggleFilter(input, false);
                }
            } else {
                swarm.reviews.toggleFilter(project, false);
            }

        }

        // set search keywords
        $(tabPane).find('.toolbar .search input').val(filters.keywords || '');
        $(tabPane).data('last-search', filters.keywords || '');
    },

    sort: function(reviews, orderBy){
        // Allow review data to be sorted by other vectors - only last updated for now
        return "updated" === orderBy
            ? $.map(reviews,function(k){return k;}).sort(function(a,b){
                var key1 = a.updated;
                var key2 = b.updated;
                return key1 < key2 ? 1 : key1 > key2 ? -1 : 0;
            })
            : reviews;
    },

    isMyProjectsFilter: function(filter) {
        if ($.isArray(filter)) {
            return true;
        }
        var prefix = 'projects-for-user:';
        if (filter.substring(0, prefix.length) === prefix) {
            return true;
        }
        return false;
    }
};

swarm.review = {
    voteIcons: {'-1' : 'icon-chevron-down', '1' : 'icon-chevron-up'},

    init: function() {
        swarm.review.initOpenFileState();

        swarm.review.initVersionChooser();

        swarm.review.initSlider();

        swarm.review.buildStateMenu();

        swarm.review.buildAddChangeMenu();

        swarm.review.updateTestStatus();

        swarm.review.updateDeployStatus();

        swarm.review.initEdit();

        swarm.review.initAuthor();

        swarm.review.initReviewers();

        swarm.review.initReadUnread();

        swarm.review.setFloat();

        // rebuild the state menu when user logs in
        $(document).on('swarm-login', function () {
            $.ajax('/reviews/' + $('.review-wrapper').data('review').id, {
                data:     {format: 'json'},
                dataType: 'json',
                success:  function (data) {
                    swarm.review.updateReview($('.review-wrapper'), data);
                }
            });
        });

        var commitPoll = function(data) {
            // rebuild the state menu if data has changed
            if (JSON.stringify(data) !== JSON.stringify($('.review-wrapper').data('review'))) {
                $('.review-wrapper').data('review', data);
                swarm.review.buildStateMenu();
            }

            // if we have errored out; stop polling
            if (data.commitStatus.error) {
                var modal = $('.review-transition.modal');
                modal.find('.messages').append('<div class="alert">' + swarm.te(data.commitStatus.error) + '</div>');
                // we should display only first two lines of the error message, the rest of them should be in an ellipsis
                var messageArray = data.commitStatus.error.split('\n');
                if (messageArray.length > 2) {
                    modal.find('.messages .alert').expander({slicePoint: data.commitStatus.error.indexOf(messageArray[2])});
                }
                return false;
            }

            // if the commit has completed, reload the page
            if ($.isEmptyObject(data.commitStatus) && !data.pending) {
                window.location.reload();
                return false;
            }
        };

        // wire-up state dropdown
        $('.review-header').on('click', '.state-menu a', function(e){
            e.preventDefault();

            var link    = $(this),
                state   = link.data('state'),
                wrapper = link.closest('.review-wrapper'),
                button  = link.closest('.btn-group').find('.btn');

            // close the dropdown
            $(document).trigger('click.dropdown.data-api');

            if (state === 'obliterate-review') {
                swarm.review.obliterate(wrapper.data());
                return false;
            }
            swarm.review.openTransitionDialog(
                state,
                wrapper.data(),
                function(modal, response) {
                    swarm.review.updateReview(wrapper, response);

                    // if we were committing, start polling for updates
                    if (state === 'approved:commit' && response.isValid) {
                        swarm.review.pollForUpdate(commitPoll);
                        return;
                    }

                    modal.modal('hide');
                    swarm.overlay.hide();
                    // indicate success via a temporary tooltip
                    button.tooltip({title: swarm.t('Review Updated'), trigger: 'manual'}).tooltip('show');
                    setTimeout(function(){
                        button.tooltip('destroy');
                    }, 3000);

                    // if a transition was made and a comment provided, refresh comments.
                    if (state !== 'approved:commit' && $.trim(modal.find('form textarea').val())) {
                        swarm.comments.load('reviews/' + wrapper.data('review').id, '#comments');
                    }
                }
            );

            return false;
        });

        // if the page just loaded and a commit is going on; keep polling for updates
        var review = $('.review-wrapper').data('review');
        if (review.commitStatus.start && !review.commitStatus.error) {
            swarm.review.pollForUpdate(commitPoll);
        }

        // wire-up description edit button
        $('.review-header').on('click', '.btn-edit', function(e){
            e.preventDefault();

            var wrapper     = $(this).closest('.review-wrapper');
            var currentUser = $('body').data('user');
            swarm.review.openEditDialog(
                wrapper.data(),
                currentUser,
                function(modal, response) {
                    modal.modal('hide');

                    // update review to reflect changes in description
                    swarm.review.updateReview(wrapper, response);
                }
            );
        });

        // wire-up the append or replace buttons
        $('.review-header').on('click', '.add-change a', function(e) {
            e.preventDefault();

            var link    = $(this),
                state   = link.data('state'),
                wrapper = link.closest('.review-wrapper'),
                status  = '',
                title   = '',
                mode    = '';

            // close the dropdown
            $(document).trigger('click.dropdown.data-api');

            // If the add replace committed button is click open dialog
            if (state === 'change-replace') {
                title  = swarm.te("Select a change to Replace the Review");
                status = 'all';
                mode   = 'replace';
                swarm.review.openChangeSelector(
                    '/reviews/add',
                    wrapper.data(),
                    swarm.review.openChangeSelectorModelCallback,
                    status,
                    title,
                    state,
                    mode
                );
            // If the add replace pending button is clicked open the dialog
            } else if (state === 'change-replace-pending') {
                title  = swarm.te("Select a pending change to Replace the Review");
                status = 'pending';
                mode   = 'replace';
                swarm.review.openChangeSelector(
                    '/reviews/add',
                    wrapper.data(),
                    swarm.review.openChangeSelectorModelCallback,
                    status,
                    title,
                    state,
                    mode
                );
            // If the add append button is clicked open the dialog
            } else if (state === 'change-append') {
                title  = swarm.te("Select a pending change to Append to the Review");
                status = 'pending';
                mode   = 'append';
                swarm.review.openChangeSelector(
                    '/reviews/add',
                    wrapper.data(),
                    swarm.review.openChangeSelectorModelCallback,
                    status,
                    title,
                    state,
                    mode
                );
            }
        });

        // Wire up the refresh projects button
        $('.review-header').on('click.refresh-projects', '.project-controls a.refresh', function(e) {
            var reviewId = $('.review-wrapper').data('review').id;
            swarm.overlay.show(true);
            $.ajax('/api/v10/reviews/' + reviewId + '/refreshProjects', {
                type:     'POST',
                dataType: 'json',
                data:     {},
                success:  function(data) {
                    window.location.reload();
                },
                error: function(){swarm.overlay.hide();}
            });
        });
        // Watch for window size change and check if we need to
        // change float of review actions buttons.
        $(window).resize(function(){
            swarm.review.setFloat();
        });

        $(document).on('click.edit.author', '.review-header .edit-author', function(e) {
            var wrapper = $('.review-wrapper');
            swarm.review.openEditAuthorDialog(wrapper.data('review'));
        });

        // Wire-up the send all notifications button
        $('.send-delayed-notifications').on('click', function(e){
            e.preventDefault();
            swarm.review.sendDelayedNotifications(this);
        });
        $('.review-wrapper').on('click.sendonpost', '.post-and-send-delayed-comments', function(e){
            e.preventDefault();
            var form = $(e.target).closest('form');
            form.find('.delay-notifications input').attr('checked', false).val(false);
            if (form.hasClass('comment-edit')) {
                swarm.comments.edit(form, true);
            } else {
                swarm.comments.add(form, true);
            }
        });
    },

    initOpenFileState: function() {
        //open files are tracked when the version selector is changed
        var openFiles = JSON.parse(swarm.localStorage.get('reviews.openFiles')) || [];

        if( openFiles.length > 0){
            var diffWrappers = $(".diff-wrapper");

            openFiles.forEach( function(file){
                var depotFile = '';
                $.each(diffWrappers, function(key, diffWrapper){
                    depotFile = $.parseJSON($(diffWrapper).attr("data-file")).depotFile;
                    if(depotFile === file){
                        $(diffWrapper).find('.diff-details').collapse('show');
                    }
                });
            });

            // set the local storage to null, this prevents opening files upon page
            // refresh and whatnot
            swarm.localStorage.set('reviews.openFiles', JSON.stringify([]));
        }
    },

    initReadUnread: function() {
        var updateStatus = function(wrapper, read, showTooltip) {
            var button = wrapper.find('.btn-file-read');
            button.toggleClass('active btn-inverse', read);
            button.find('i').toggleClass('icon-white', read);
            wrapper.toggleClass('file-read',   read);
            wrapper.toggleClass('file-unread', !read);

            if (showTooltip) {
                // update tooltip with temporary confirmation text
                button.attr('data-original-title', read ? swarm.t('Marked file as read') : swarm.t('Marked file as unread'));
                button.tooltip('show');

                // switch back to action verbiage shortly thereafter
                setTimeout(function(){
                    button.attr('data-original-title', read ? swarm.t('Mark file as unread') : swarm.t('Mark file as read'));
                }, 1000);
            } else {
                // not showing the tooltip, go straight to the action verbiage
                button.attr('data-original-title', read ? swarm.t('Mark file as unread') : swarm.t('Mark file as read'));
            }
        };

        // on login, update read status
        $(document).on('swarm-login', function(e){
            // loop over each file and see if our user has read it.
            // if so, check the 'read by' box
            $('.change-files .diff-wrapper').each(function() {
                var wrapper = $(this);
                if (e.hasOwnProperty('user') && wrapper.data('readby').hasOwnProperty(e.user.id)) {
                    updateStatus(wrapper, true, false);
                }
            });
        });

        // connect 'file-read' buttons so users can check off files
        $('.change-files').on('click', '.btn-file-read', function(e){
            var button  = $(this),
                read    = !button.is('.active'),
                review  = button.closest('.review-wrapper').data('review'),
                change  = button.closest('.review-wrapper').data('change'),
                against = button.closest('.review-wrapper').data('against'),
                version = (against ? against.rev + ',' : '') + change.rev,
                wrapper = button.closest('.diff-wrapper'),
                file    = wrapper.data('file'),
                details = wrapper.find('.diff-details');

            // update file-info on the server, don't wait for a response
            // as it doesn't impact UI except to slow it down
            $.ajax({
                type:  "POST",
                url:   '/reviews/' + review.id + '/v' + version
                + '/files/' + swarm.encodeURIDepotPath(file.depotFile),
                data:  {read: read ? 1 : 0, user: swarm.user.getAuthenticatedUser().id},
                error: function() {
                    updateStatus(wrapper, !read, true);
                }
            });

            updateStatus(wrapper, read, true);

            // collapse file if expanded and marking as 'read'
            if (details.is('.in') && read) {
                details.one('hidden', function(){
                    if (button.data('tooltip').tip().parent().length) {
                        button.tooltip('show');
                    }
                });
                details.collapse('hide');
            }
        });
    },

    initAuthor: function() {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            author  = wrapper.data('author-avatar'),
            canEdit = wrapper.data('can-edit-author'),
            avatars = wrapper.data('avatars') || {};

        // add author avatar
        $('.review-header .author-avatar').html(author[0]);


        // if user can edit author create author change box
        if (canEdit) {
            $('.review-header .author-change').html($.templates(
                '<div class="text-left pull-left muted">'
                +     '<div>'
                +     swarm.te('Author')
                +     '<button type="button" class="bare privileged edit-author pad0" title="" data-original-title="' + swarm.te('Edit Author') + '">'
                +         '<i class="swarm-icon icon-edit-pencil"></i>'
                +     '</button></div>'
                + '</div>'
                + '<div class="author-small-avatar pull-left">'
                +     '{{:avatar}}'
                + '</div>').render({avatar: avatars[review.author] || ''}));

        }

        // update author on login
        $(document).on('swarm-login', function (e) {
            swarm.review.initAuthor();
        });
    },

    initSlider: function() {
        var data       = [],
            wrapper    = $('.review-wrapper'),
            review     = wrapper.data('review'),
            change     = wrapper.data('change'),
            against    = wrapper.data('against'),
            changeId   = parseInt(change.id, 10),
            changeRev  = parseInt(change.rev, 10),
            againstId  = against ? parseInt(against.id, 10)  : null,
            againstRev = against ? parseInt(against.rev, 10) : null;

        $.each(review.versions, function(index) {
            this.rev      = index + 1;
            this.change   = parseInt(this.change, 10);
            this.selected = (this.change === changeId  && this.rev === changeRev)
                || (this.change === againstId && this.rev === againstRev);
            data.push(this);
        });

        $('.review-slider').versionSlider({data: data, markerMode: against ? 2 : 1});
        $('.slider-mode-toggle').toggleClass('active', !!against);

        $(document).off('slider-moved', '.review-slider').on('slider-moved', '.review-slider', function(e, slider) {
            setTimeout(function() {
                var version = (slider.previousRevision ? slider.previousRevision.rev + ',' : '')
                    + slider.currentRevision.rev;
                var path    = document.location.pathname.replace(/(\/v[0-9,]+)?\/?$/, '/v' + version);
                if (path !== document.location.pathname) {
                    slider.disable();

                    swarm.review.setLocalOpenFilesState();

                    document.location = path + document.location.hash;
                }
            }, 0);
        });

        $(document).off('click.slider.mode.toggle').on('click.slider.mode.toggle', '.slider-mode-toggle', function() {
            var slider = $('.review-slider').data('versionSlider');
            slider.setMarkerMode(slider.markerMode === 1 ? 2: 1);
            slider.$element.trigger('slider-moved', slider);
        });
    },

    initVersionChooser: function() {
        var data        = [],
            wrapper     = $('.review-wrapper'),
            review      = wrapper.data('review'),
            versions    = review?review.versions.length:0,
            change      = wrapper.data('change'),
            against     = wrapper.data('against'),
            changeId    = parseInt(change.id, 10),
            changeRev   = parseInt(change.rev, 10),
            againstId   = against ? parseInt(against.id, 10)  : null,
            againstRev  = against ? parseInt(against.rev, 10) : 0,
            head        = wrapper.data('head'),
            fromOptions = "<option value=''" + (head ? ' selected=selected':'')+">"+swarm.te("Current version in depot")+"</option><option value='0'"
                + (againstRev===0 && !head ? " selected=selected":"")+">" + swarm.te("Base version for this review")
                + "</option>";
        var toOptions   = "";
        var reviewTime  = swarm.timeFormatter.timeValue(review.created);
        var initialToOption = "<option value=''>"
            + swarm.te(
                '#1 by %s - %s - shelved in %s',
                [review.author, swarm.timeFormatter.convert(reviewTime), review.id])
            + "</option>";
        $.each(review.versions, function(index) {

            this.rev      = index + 1;
            this.change   = parseInt(this.change, 10);
            this.selected = (this.change === changeId  && this.rev === changeRev)
                || (this.change === againstId && this.rev === againstRev);
            data.push(this);
            var timeConverted = swarm.timeFormatter.timeValue(this.time);
            if (this.rev<versions) {
                fromOptions+='<option value="'+(index+1)+'"'
                    +((this.change === againstId && this.rev === againstRev)
                        ? 'selected="selected"'
                        : (this.rev && changeRev && this.rev >= changeRev ? 'disabled="disabled"' : ''))+'>'
                    + (this.pending
                        ? swarm.te(
                            '#%d by %s - %s - shelved in %s',
                            [this.rev, this.user, swarm.timeFormatter.convert(timeConverted), this.change ])
                        : swarm.te(
                            '#%d by %s - %s - committed in %s',
                            [this.rev, this.user, swarm.timeFormatter.convert(timeConverted), this.change ]))
                    + '</option>';
            }
            toOptions+='<option value="'+(index+1)+'"'
                +((this.change === changeId  && this.rev === changeRev)
                    ? 'selected="selected"'
                    : (this.rev && againstRev && this.rev <= againstRev ? 'disabled="disabled"' : ''))+'>'
                + (this.pending
                    ? swarm.te(
                        '#%d by %s - %s - shelved in %s',
                        [this.rev, this.user, swarm.timeFormatter.convert(timeConverted), this.change ])
                    : swarm.te(
                        '#%d by %s - %s - committed in %s',
                        [this.rev, this.user, swarm.timeFormatter.convert(timeConverted), this.change ]))
                +'</option>';
        });
        $('#revision-select-from').html(fromOptions);
        $('#revision-select-to').html(toOptions||initialToOption);

        var selectedRevision = $('select#revision-select-to').val();
        var topRevision      = $('select#revision-select-to option').length;
        var displayPaneValue = swarm.te('(revision %s of %s)',[selectedRevision, topRevision ]);
        $('#revision-display-pane').html(displayPaneValue);

        $('.review-version-chooser').off('change.version','.revision-select').on('change.version','.revision-select', function(e) {
            setTimeout(function() {
                var version = $('#revision-select-from').val()+','+$('#revision-select-to').val();
                var path    = document.location.pathname.replace(/(\/v[0-9,]+)?\/?$/, '/v' + version);
                if (path !== document.location.pathname) {
                    $('.revision-select').attr('disabled', true);
                    swarm.review.setLocalOpenFilesState();

                    document.location = path + document.location.hash;
                }
            }, 0);
        });
    },

    initEdit: function() {
        // add edit button after the first line (if its not already there)
        if ($('.review-header .change-description .btn-edit').length) {
            return;
        }

        $('<a href="#" class="privileged btn-edit" title="' + swarm.te('Edit Description') + '">'
            + '<i class="swarm-icon icon-review-needsRevision"></i>'
            + '</a>'
        ).insertAfter('.review-header .change-description .first-line');
    },

    setLocalOpenFilesState: function(){
        //keep track of open files so we can re-open them when the user changes version selection
        var openFiles = $(".diff-wrapper:not('.collapsed')").map(function(){
            return $.parseJSON($(this).attr("data-file")).depotFile;
        }).get();

        swarm.localStorage.set('reviews.openFiles', JSON.stringify(openFiles));
    },

    initReviewers: function() {
        // create reviewers templates only once
        $.templates({
            userMenu:
            '<ul role="menu" class="dropdown-menu user minimum-{{:minimumRequired}}" aria-label="{{te:"User Reviewer Menu"}}">'
            +   '{{if vote.value < 1 || vote.isStale}}'
            +     '<li role="menuitem"><a href="#" data-action="up">'
            +       '<i class="icon-chevron-up"></i> {{te:"Vote Up"}}'
            +     '</a></li>'
            +   '{{/if}}'
            +   '{{if vote.value !== 0}}'
            +     '<li role="menuitem"><a href="#" data-action="clear">'
            +       '<i class="icon-minus"></i> {{te:"Clear Vote"}}'
            +     '</a></li>'
            +   '{{/if}}'
            +   '{{if vote.value > -1 || vote.isStale}}'
            +     '<li role="menuitem"><a href="#" data-action="down">'
            +       '<i class="icon-chevron-down"></i> {{te:"Vote Down"}}'
            +     '</a></li>'
            +   '{{/if}}'
            +   '<li role="presentation" class="divider"></li>'
            +   '{{if addReviewer || !participant}}'
            +     '<li role="menuitem"><a href="#" data-action="join">'
            +       '<i class="icon-plus"></i> {{te:"Join Review"}}'
            +     '</a></li>'
            +   '{{else}}'
            +    '{{if participant}}'
            +     '{{if isRequired || groupRequired}}'
            +       '{{if !groupRequired}}'
            +         '<li class="optional-reviewer" role="menuitem"><a href="#" data-action="optional">'
            +           '<i class="icon-star-empty"></i> {{te:"Make my Vote Optional"}}'
            +         '</a></li>'
            +       '{{/if}}'
            +     '{{else}}'
            +       '<li class="required-reviewer" role="menuitem"><a href="#" data-action="required">'
            +         '<i class="icon-star"></i> {{te:"Make my Vote Required"}}'
            +       '</a></li>'
            +     '{{/if}}'
            +     '{{if notificationsDisabled}}'
            +       '<li role="menuitem"><a href="#" data-action="enableNotifications">'
            +         '<i class="icon-envelope"></i> {{te:"Enable Notifications"}}'
            +       '</a></li>'
            +     '{{else}}'
            +       '<li role="menuitem"><a href="#" data-action="disableNotifications">'
            +         '<i class="swarm-icon icon-disable-notifications"></i> {{te:"Disable Notifications"}}'
            +       '</a></li>'
            +     '{{/if}}'
            +     '<li class="withdraw-reviewer" role="menuitem"><a href="#" data-action="leave"><i class="icon-remove"></i> {{te:"Leave Review"}}</a></li>'
            +   '{{/if}}'
            +  '{{/if}}'
            + '</ul>',
            groupMenu:
            '<ul role="menu" class="dropdown-menu pull-right type-group item-require minimum-{{:minimumRequired}}" aria-label="{{te:"Group Reviewer Menu"}}">'
            +   '<li role="menuitem"><a href="' + $('body').data('base-url') + '/groups/{{:userId}}">{{:userId}}</a></li>'
            +   '{{if canEdit}}'
            +   '<li role="presentation" class="divider"></li>'
            +   '<li class="optional-reviewer" role="menuitem"><a href="#" data-action="optional"><i class="icon-star-empty"></i> {{te:"Make Vote Optional"}}</a></li>'
            +   '<li class="one-reviewer" role="menuitem"><a href="#" data-action="oneRequired"><i class="icon-star">1</i> {{te:"Make One Vote Required"}}</a></li>'
            +   '<li class="required-reviewer" role="menuitem"><a href="#" data-action="allRequired"><i class="icon-star"></i> {{te:"Make All Votes Required"}}</a></li>'
            +   '<li class="withdraw-reviewer" role="menuitem"><a href="#" data-action="leave"><i class="icon-share"></i> {{te:"Withdraw group from review"}}</a></li>'
            +   '{{/if}}'
            +   '<li role="presentation" class="divider"></li>'
            +   '<div class="group-members-votes multipicker-item" id="{{:userId}}-votes">'
            +   '</div>'
            + '</ul>',
            reviewerAvatar:
            '<div {{if groupDisabled}}disabled="disabled" {{/if}}data-value="reviewer-avatar-{{:userId}}" class="reviewer-avatar pull-left '
            +     '{{if isRequired === "1"}} requiredOne {{else isRequired}} requiredAll {{/if}}'
            +     '{{if current}}current{{/if}}{{if group}}type-group{{/if}} {{if addReviewer}}add-reviewer{{/if}}" {{if current}}id="currentUser"{{/if}}>'
            +   '{{if current || group }}'
            +   '{{if groupDisabled}}<div class="disabled"></div>{{/if}}'
            +     '<div {{if groupDisabled}}disabled="disabled" {{/if}}class="btn pad1 dropdown-toggle" tabIndex="0" data-toggle="dropdown" role="button" aria-haspopup="true">'
            +   '{{/if}}'
            +   '{{:avatar}}'
            +   '{{if vote.value > 0}}'
            +     '<i class="swarm-icon {{if vote.isStale}}icon-vote-up-stale{{else}}icon-vote-up{{/if}}"></i>'
            +   '{{else vote.value < 0}}'
            +     '<i class="swarm-icon {{if vote.isStale}}icon-vote-down-stale{{else}}icon-vote-down{{/if}}"></i>'
            +   '{{/if}}'
            +   '{{if isRequired || groupRequired}}'
            +     '<i class="swarm-icon icon-required-reviewer">{{if isRequired === "1"}}{{:isRequired}}{{/if}}</i>'
            +   '{{/if}}'
            +   '{{if current || group}}'
            +     '<i class="caret"></i></div>'
            +     '{{if current tmpl="userMenu" /}}'
            +     '{{if group tmpl="groupMenu" /}}'
            +   '{{/if}}'
            + '</div>',
            voteSummary:
            '<div class="vote-summary text-left pull-left muted">'
            +   '<div>'
            +     '{{te:"Reviewers"}}'
            +     '{{if canEdit}}'
            +     '<button type="button" class="bare privileged edit-reviewers pad0 padw1"'
            +             'aria-label="{{te:"Edit Reviewers"}}" title="{{te:"Edit Reviewers"}}">'
            +       '<i class="swarm-icon icon-edit-pencil"></i>'
            +     '</button>'
            +     '{{/if}}'
            +   '</div>'
            +   '<span class="vote-up {{if upCount}}has-value{{/if}}" title="{{te:"Up Votes"}}">'
            +     '<i class="icon-chevron-up"></i>{{>upCount}}'
            +   '</span>'
            +   '<span class="vote-down {{if downCount}}has-value{{/if}}" title="{{te:"Down Votes"}}">'
            +     '<i class="icon-chevron-down"></i>{{>downCount}}'
            +   '</span>'
            + '</div>',
            editReviewersDialog:
            '<div class="modal hide fade edit-reviewers" tabindex="-1" role="dialog" aria-labelledby="reviewers-edit-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form">'
            +     '<div class="modal-header">'
            +       '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +       '<h3 id="reviewers-edit-title">{{te:"Reviewers"}}</h3>'
            +     '</div>'
            +     '<div class="modal-body">'
            +       '<div class="messages"></div>'
            +       '<div class="controls reviewers">'
            +         '<div class="input-prepend" clear="both">'
            +           '<span class="add-on"><i class="icon-user"></i></span>'
            +           '<input type="text" class="input-xlarge reviewer-multipicker" data-items="100"'
            +                  'placeholder="{{te:"Reviewer Name"}}">'
            +         '</div>'
            +         '<div class="reviewers-list"></div>'
            +       '</div>'
            +     '</div>'
            +     '<div class="modal-footer">'
            +       '<button type="submit" class="btn btn-primary">{{te:"Save"}}</button>'
            +       '<button type="button" class="btn" data-dismiss="modal">{{te:"Close"}}</button>'
            +     '</div>'
            +   '</form>'
            + '</div>',
            requiredReviewerButton:
            '<button type="button" class="btn btn-mini btn-info item-require minimum-{{:minimumRequired}} {{if isRequired}}active{{/if}}" data-toggle="{{if isGroup}}dropdown{{else}}button{{/if}}"'
            + ' title="{{if isRequired}}{{te:"Make Vote Optional"}}{{else}}{{te:"Make Vote Required"}}{{/if}}"'
            + ' aria-label="{{if isRequired}}{{te:"Make Vote Optional"}}{{else}}{{te:"Make Vote Required"}}{{/if}}">'
            +   '<i class="{{if isRequired}}icon-star{{else}}icon-star-empty{{/if}} icon-white{{if isRequired === "1"}} requiredOne {{else isRequired}} requiredAll {{/if}}">{{if isRequired === "1"}}{{:isRequired}}{{/if}}</i>'
            +   '<input type="hidden" class="requirement" name="requiredReviewers[]" value="{{>value}}" {{if !isRequired}}disabled{{/if}}>'
            + '</button>'
            + '{{if isGroup}}'
            +   '<input type="hidden" class="quorum preserve-value" name="reviewerQuorum[{{>value}}]" value="{{>isRequired}}" {{if isRequired !== "1" }}disabled="disabled"{{/if}}>'
            +   '<ul class="group-required dropdown-menu">'
            +     '<li class="optional"><a href="#" data-required="false"><i class="icon-star-empty"></i> {{te:"Make Vote Optional"}}</a></li>'
            +     '<li class="one"><a href="#" data-required="1"><i class="icon-star">1</i> {{te:"Make One Vote Required"}}</a></li>'
            +     '<li class="all"><a href="#" data-required="true"><i class="icon-star"></i> {{te:"Make All Votes Required"}}</a></li>'
            +   '</ul>'
            + '{{/if}}'

        });

        swarm.review.buildReviewers();
        swarm.review.initUserMenu();
        swarm.review.initGroupMenu();

        // update add reviewer avatar on login
        $(document).on('swarm-login', function (e) {
            swarm.review.buildReviewers();
        });

        $(document).on('click.edit.reviewers', '.review-header .edit-reviewers', function(e) {
            var wrapper = $('.review-wrapper');
            var editButton = $(e.target).closest('button');
            editButton.prop('disabled', true);
            swarm.review.openEditReviewersDialog(wrapper.data('review'), function(modal, response) {
                // close modal and update review to reflect reviewer changes
                modal.modal('hide');
                swarm.review.updateReview(wrapper, response);
            });
            $(document).off('shown','.modal.edit-reviewers')
                .on('shown.edit-reviewers','.modal.edit-reviewers', function() {
                    editButton.prop('disabled', false);
                });
        });

        $(document).on("click.dropdown-toggle", '.dropdown-toggle', function(e) {
            var dropdown = $(this);
            var menu = dropdown.next();
            menu.addClass('pull-right');
            if( menu.is(':off-right') ) {
                menu.addClass('pull-right');
            }
            if( menu.is(':off-left') ) {
                menu.removeClass('pull-right');
            }
        });
    },

    isCurrentUserMemberOfGroups: function(groups) {
        // fetch user data.
        var userData   = $('body').data('user');
        var userGroups = userData.groups;
        var key = '';
        // Loop though user groups and see if they are in the groups apart of this review.
        for (key in userGroups) {
            if (userGroups.hasOwnProperty(key) && groups.indexOf(key) !== -1) {
                return true;
            }
        }

        // Didn't find a group.
        return false;
    },

    isCurrentUserInRequiredGroup: function(reviewers, details) {
        // fetch user data.
        var userData    = $('body').data('user');
        var userGroups  = userData.groups;
        var key         = '';
        var reviewer    = '';
        var groupPrefix = 'swarm-group-';

        // Loop though user groups and see if they are in the groups apart of this review.
        for (key in userGroups){
            if(userGroups.hasOwnProperty(key) && reviewers.indexOf('swarm-group-'+key) !== -1) {
                 reviewer = groupPrefix + key;
                // check if required is set for any group and return at the first one found.
                if (details[reviewer].required === true || details[reviewer].required === 'true' ) {
                    return true;
                }
            }
        }
        // Not a required group in list for current user
        return false;
    },

    buildCurrentUserAvatar: function(user) {
        var avatarWrapper = $(user.avatar),
            avatar        = avatarWrapper.find('.avatar');

        // tweak size and styling of user's avatar before inserting
        avatarWrapper.removeClass('fluid');
        avatar
            .attr('width',  40)
            .attr('height', 40)
            .attr('src',    avatar.attr('src').replace(/s=[0-9]+/,    's=40'))
            .attr('class',  avatar.attr('class').replace(/as-[0-9]+/, 'as-40'));
        return avatarWrapper[0].outerHTML;
    },

    buildReviewers: function() {
        var wrapper           = $('.review-wrapper'),
            review            = wrapper.data('review'),
            canEdit           = wrapper.data('can-edit-reviewers'),
            avatars           = wrapper.data('avatars') || {},
            reviewers         = $.grep(review.participants, function(id) { return String(id) !== String(review.author); }).map(String),
            user              = swarm.user.getAuthenticatedUser(),
            userId            = user && String(user.id),
            details           = review.participantsData || {},
            defaultVote       = {value: 0, version: undefined, isStale: undefined},
            groupsMembership  = wrapper.data('review-groups-members'),
            reviewAuthor      = String(review.author);


        // get count of all non-state up and down votes
        var upCount   = 0,
            downCount = 0;
        $.each(details, function (user, data) {
            if (data.vote && !data.vote.isStale) {
                upCount   += data.vote.value > 0 ? 1 : 0;
                downCount += data.vote.value < 0 ? 1 : 0;
            }
        });

        // only show the reviewers label when we have reviewers, or the
        // active user has the ability to edit the reviewers list
        var html = '';
        var totalGroups = 0;
        var totalUsers  = 0;
        var groupHtml = '<div class="groupReviewers pull-left muted" id="reviewersGroups"><div class="span12"><span class="pull-left">' + swarm.te("Groups") + ':</span></div><div>';
        var userHtml  = '<div class="individualReviewers pull-left muted" id="reviewersIndividuals"><div class="span12"><span class="pull-left span12">' + swarm.te("Individuals") + ':</span></div><div>';
        var actionBoard = '';

        if (reviewers.length || canEdit) {
            actionBoard += $.templates.voteSummary.render({
                upCount:   upCount,
                downCount: downCount,
                reviewers: reviewers,
                isAuthor:  userId === reviewAuthor,
                canEdit:   canEdit
            });
        }
        var groups = [];
        $.each(reviewers, function(key, reviewer) {
            if (reviewer !== userId) {
                if (reviewer.indexOf('swarm-group-') === 0) {
                    var groupId = reviewer.replace('swarm-group-', '');
                    groupHtml += $.templates.reviewerAvatar.render({
                        vote: details[reviewer].vote || defaultVote,
                        avatar: avatars[reviewer] || '',
                        isRequired: details[reviewer].required,
                        minimumRequired: details[reviewer].minimumRequired,
                        notificationsDisabled: !!details[reviewer].notificationsDisabled,
                        userId: groupId,
                        group: true,
                        groupDisabled: true,
                        canEdit: canEdit
                    });
                    totalGroups++;
                    groups.push(groupId);
                } else {
                    // Check if the config option is enabled to explain individuals.
                    // Then check if they are required and if they are show them no matter if option set or not.
                    // Then check that the reviewer is not a participant but in a group member.
                    if (swarm.config.get("reviews.expand_group_reviewers") === 'true'
                        || details[reviewer].required
                        || $.inArray(reviewer, groupsMembership) === -1) {
                        userHtml += $.templates.reviewerAvatar.render({
                            vote: details[reviewer].vote || defaultVote,
                            avatar: avatars[reviewer] || '',
                            isRequired: details[reviewer].required,
                            minimumRequired: details[reviewer].minimumRequired,
                            notificationsDisabled: !!details[reviewer].notificationsDisabled,
                            userId: reviewer
                        });
                        totalUsers++;
                    }
                }
            }
        });
        groupHtml += '</div></div>';
        userHtml += '</div></div>';
        var currentUserTitle = '';
        if (totalGroups > 0) {
            html += groupHtml;
            currentUserTitle = '<span class="pull-left span12"></span>';
        }
        if (totalUsers > 0) {
            html += userHtml;
            currentUserTitle = '<span class="pull-left span12"></span>';
        }
        var participantByGroup = false;
        if(userId && userId !== reviewAuthor){
            participantByGroup = this.isCurrentUserMemberOfGroups(groups);
        }
        // if current user is a reviewer, show their avatar last
        // otherwise, show add-reviewer if user is authenticated and not the author
        var userParticipant = $.inArray(userId, reviewers) !== -1;
        if (userParticipant|| participantByGroup) {
            var userVote         = defaultVote;
            var userRequired     = 0;
            var groupRequired    = this.isCurrentUserInRequiredGroup(reviewers, details);
            var userNotification = 0;
            var userAvatar       = this.buildCurrentUserAvatar(user);
            var minimumRequired  = false;
            if (userParticipant) {
                userVote     = details[userId].vote || defaultVote;
                minimumRequired = details[userId].minimumRequired;
                if(!this.isCurrentUserInRequiredGroup(reviewers, details)) {
                    userRequired = !!details[userId].required;
                    minimumRequired = details[userId].minimumRequired;
                }
                userNotification = !!details[userId].notificationsDisabled;
                userAvatar   = avatars[userId] || '';
            }
            html += '<div class="pull-left muted">' + currentUserTitle + $.templates.reviewerAvatar.render({
                vote:                  userVote,
                avatar:                userAvatar,
                current:               true,
                isRequired:            userRequired,
                minimumRequired:       minimumRequired,
                notificationsDisabled: userNotification,
                userId:                userId,
                participant:           userParticipant,
                groupRequired:         groupRequired
            })+'</div>';
        } else if (userId && userId !== reviewAuthor) {
            html += '<div class="pull-left muted">' + currentUserTitle + $.templates.reviewerAvatar.render({
                vote:            defaultVote,
                avatar:          this.buildCurrentUserAvatar(user),
                current:         true,
                minimumRequired: false,
                addReviewer:     true,
                userId:          userId,
                participant:     userParticipant
            })+'</div>';
        }
        // Create the vote summary
        var $reviewActionNode = $('#votes-actions');
        $reviewActionNode.html(actionBoard);
        // destroy existing tooltips so they don't get orphaned
        var $reviewersNode = $('.review-header .reviewers');
        $reviewersNode.find('[title]').tooltip('destroy');

        $reviewersNode.html(html);

        // we don't want the active user's avatar to be a link or have a tooltip - switch it to a div
        $reviewersNode
            .find('.current .btn .avatar')
            .unwrap()
            .wrapAll('<div class="avatar-wrapper">');

        // tweak users' avatar tooltips to show the version they voted on
        $reviewersNode.find('.avatar-wrapper').attr('title', '').data('customclass', 'user-vote').tooltip({
            container:   'body',
            trigger:     'manual',
            isDelegated: true,
            html:        true,
            title:       function(){
                var name   = $(this).find('img').attr('alt'),
                    userId = $(this).find('img').data('user'),
                    vote   = details[userId] && details[userId].vote ? details[userId].vote : {};

                return $.templates(
                    '<span class="name">{{>name}}</span>'
                    + '{{if vote.value}}'
                    +   '<br><span class="muted">'
                    +     '{{if vote.value > 0}}{{te:"voted up"}}{{else}}{{te:"voted down"}}{{/if}}'
                    +     '{{if !vote.isStale}} {{te:"latest"}}{{else}} #{{>vote.version}}{{/if}}'
                    +   '</span>'
                    + '{{/if}}'
                ).render({name: name, vote: vote});
            }
        });

        // Now fire the ajax calls for group votes
        swarm.review.initGroupUsers(details, reviewAuthor);
    },

    initGroupUsers: function(details, author) {
        var groups = $('#reviewersGroups .reviewer-avatar');
        // create reviewers templates only once
        $.templates({
            groupPills:
            '<div class="btn-group">'
            +   '<button type="button" class="btn btn-mini btn-{{:Colour}} button-name{{if NoVote}} novote{{/if}}" data-original-title="{{:Id}}" title="{{:Id}}{{if FullName}} ({{:FullName}}){{/if}}">{{:Id}}</button>'
            + '{{if Vote}}'
            +   '<button type="button" class="btn btn-mini btn-{{:Colour}}"><i class="{{:Vote}} icon-white"></i></button>'
            + '{{/if}}'
            + '</div>'
        });
        // For each group button on the reviews page.
        $.each(groups, function (key, group) {
            group = $(group);
            var groupId        = group.data("value"),
                userHtml       = '',
                authorHtml     = '';

            // string the Id of the class
            groupId = groupId.replace('reviewer-avatar-', '');
            $.ajax('/api/v9/users',{
                type: 'GET',
                dataType: 'json',
                data: {fields: ['User','FullName'], group: groupId},
                success:  function(data) {
                    var currentGroup   = group.find('#'+groupId+'-votes');
                    // For each user in the group fetch details if we have them.
                    $.each(data.filter(function (user) {
                        // Create the Authors pill for each group as they might be in the group but their vote
                        // doesn't count.
                        if (user.User === author) {
                            authorHtml = $('<span><b>' + swarm.te("Exempt (review owner)") + ':</b></span>');
                            var fullName = (author !== user.FullName) ? user.FullName:'';
                            authorHtml.append('<div class="vote-container"></div>')
                                .append(
                                    $.templates.groupPills.render({
                                        Id: author,
                                        Colour: 'info',
                                        FullName: fullName,
                                        NoVote: true
                                    })
                                );
                        }
                        return user.User !== author;
                    }), function(key, user){
                        var fullName = (author !== user.FullName) ? user.FullName:'';
                        user         = user.User;
                        var voteIcon = false;
                        var noVote   = true;
                        // If we are on the first element add the span
                        if (key === 0) {
                            currentGroup.append('<span><b>' + swarm.te("Members") + ':</b></span>');
                            userHtml = $('<div class="vote-container"></div>');
                        }
                        // Then for each user get the vote status if they have one
                        if (details.hasOwnProperty(user) && details[user].hasOwnProperty("vote")) {
                            voteIcon = swarm.review.voteIcons[details[user].vote.value];
                            noVote   = false;
                            if (details[user].vote.hasOwnProperty("isStale") && details[user].vote.isStale === true) {
                                voteIcon = voteIcon+"-stale";
                            }
                        }
                        // create the user pill icons
                        userHtml.append($.templates.groupPills.render({
                            Id:       user,
                            Colour:   'info',
                            FullName: fullName,
                            NoVote:   noVote,
                            Vote:     voteIcon
                        }));
                    });
                    currentGroup.append(userHtml);
                    currentGroup.append(authorHtml);

                    // When there is 15 or more members of the group explain the min-width for menu
                    if (data.length >= 15) {
                        group.find('ul').css({
                            "min-width": "600px"
                        });
                    }

                    // Now un disable buttons
                    group.find('.disabled').removeClass('disabled');
                    group.attr("disabled", false);
                    group.children().attr("disabled", false);
                },
                complete: function() {
                    swarm.review.totalGroupVotes(group);
                }
            });
        });
    },

    activeGroupVotes: function (upVotes, downVotes) {
        return (upVotes + downVotes) > 0 ? true : false;
    },

    totalGroupVotes: function(group) {

        var groupVotes     = group.find('.group-members-votes');
        var totalMembers   = groupVotes.find('.vote-container').first().find('div').length;
        var totalVoteUp    = groupVotes.find('.icon-chevron-up').length;
        var totalVoteDown  = groupVotes.find('.icon-chevron-down').length;
        var arrowDirection = '';

        var totalVoteUpStale   = groupVotes.find('.icon-chevron-up-stale').length;
        var totalVoteDownStale = groupVotes.find('.icon-chevron-down-stale').length;

        if (group.hasClass('requiredAll') === true) {
            if (swarm.review.activeGroupVotes(totalVoteUp, totalVoteDown)) {
                arrowDirection = (totalMembers === totalVoteUp) ? 'up'
                    : (totalVoteDown > 0) ? 'down' : '';
            } else {
                arrowDirection = (totalMembers === totalVoteUpStale) ? 'up-stale'
                    : (totalVoteDownStale > 0) ? 'down-stale' : '';
            }
        } else {
            // requiredOne or no class signifying optional
            if (swarm.review.activeGroupVotes(totalVoteUp, totalVoteDown)) {
                arrowDirection = (totalVoteDown > 0) ? 'down' : (totalVoteUp > 0) ? 'up' : '';
            } else {
                arrowDirection = (totalVoteDownStale > 0) ? 'down-stale' : (totalVoteUpStale > 0) ? 'up-stale' : '';
            }
        }
        if (arrowDirection !== '') {
            var icon = '<i class="swarm-icon icon-vote-' + arrowDirection + '"></i>';
            group.find('.avatar-wrapper:first-child').after(icon);
        }
    },

    initUserMenu: function() {
        // handle clicks within the dropdown menu
        $(document).off('click.review.user.menu');
        $(document).on('click.review.user.menu', '.review-header .reviewers .dropdown-menu.user a', function(e) {
            e.preventDefault();

            var $this  = $(this),
                action = $this.data('action');

            var callback = function(request, status) {
                var reviewer = $('.review-header .reviewers .current');
                reviewer.removeClass('open');
                var avatar = reviewer.find('.avatar-wrapper'),
                    actionStatus = '',
                    oldTip = avatar.attr('data-original-title');
                // indicate success via a temporary tooltip.
                if (action === 'join' || action === 'leave') {
                    actionStatus = action === 'join' ? swarm.t('Joined') : swarm.t('Left');
                }
                if (action === 'disableNotifications' || action === 'enableNotifications') {
                    actionStatus = action === 'enableNotifications' ? swarm.t('Notifications enabled') : swarm.t('Notifications disabled');
                }

                if (actionStatus && status === 'success') {
                    avatar.attr('data-original-title', actionStatus).tooltip('show');
                    setTimeout(function(){
                        avatar.attr('data-original-title', oldTip).tooltip('hide');
                    }, 3000);
                }
            };

            if (action === 'join') {
                swarm.review.join(callback);
                return;
            }
            if (action === 'leave') {
                swarm.review.leave(callback);
                return;
            }
            if (action === 'required' || action === 'optional') {
                swarm.review.setRequiredReviewer(action === 'required', callback);
                return;
            }

            if (action === 'disableNotifications' || action === 'enableNotifications') {
                swarm.review.disableNotifications(action==='disableNotifications', callback);
                return;
            }

            swarm.review.vote(action, callback);
        });
    },

    initGroupMenu: function() {
        // handle clicks within the dropdown menu
        $(document).off('click.review.type-group.menu');
        $(document).on('click.review.type-group.menu', '.review-header .reviewers .dropdown-menu.type-group a', function(e) {

            var $this   = $(this),
                action  = $this.data('action'),
                group  = $this.parent().parent().parent(),
                groupId = group.data('value').replace('reviewer-avatar-', '');

            var callback = function(request, status) {
                group.removeClass('open');
                var avatar = group.find('.avatar-wrapper'),
                    actionStatus = '',
                    oldTip = avatar.attr('data-original-title');

                if (action === 'leave') {
                    actionStatus = swarm.t('Left');
                }
                if (actionStatus && status === 'success') {
                    avatar.attr('data-original-title', actionStatus).tooltip('show');
                    setTimeout(function(){
                        avatar.attr('data-original-title', oldTip).tooltip('hide');
                    }, 3000);
                }
            };

            if (action === 'leave') {
                swarm.review.groupLeave(callback, groupId);
                return;
            }

            if (action === 'optional') {
                swarm.review.groupSetRequiredReviewer(false, 'swarm-group-'+groupId, callback);
                return;
            }

            if (action === 'allRequired') {
                swarm.review.groupSetRequiredReviewer(true, 'swarm-group-'+groupId, callback);
                return;
            }

            if (action === 'oneRequired') {
                swarm.review.groupSetRequiredReviewer(1, 'swarm-group-'+groupId, callback);
                return;
            }
        });
    },

    groupLeave: function(callback, groupId) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review');
        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent('swarm-group-'+groupId) + '?_method=DELETE', {
            type:     'POST',
            dataType: 'json',
            success:  function(data) {
                swarm.review.updateGroupsMembers(data.groupsMembership);
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },
    updateGroupsMembers: function(data) {
      $('.review-wrapper').data('review-groups-members', data);
    },

    groupSetRequiredReviewer: function(isRequired, groupId, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review');

        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent(groupId) + '?_method=PATCH', {
            type:     'POST',
            dataType: 'json',
            data:     {required: isRequired},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    leave: function(callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent(user.id) + '?_method=DELETE', {
            type:     'POST',
            dataType: 'json',
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    join: function(callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id, {
            type:     'POST',
            dataType: 'json',
            data:     {join: user.id},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    vote: function(action, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/vote/' + action, {
            type:     'POST',
            dataType: 'json',
            data:     {user: String(user.id), version: review.versions.length},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    setRequiredReviewer: function(isRequired, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent(user.id) + '?_method=PATCH', {
            type:     'POST',
            dataType: 'json',
            data:     {required: isRequired},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    disableNotifications: function(notificationsDisabled, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review'),
            user    = swarm.user.getAuthenticatedUser();
        $.ajax('/reviews/' + review.id + '/reviewers/' + encodeURIComponent(user.id) + '?_method=PATCH', {
            type:     'POST',
            dataType: 'json',
            data:     {notificationsDisabled: notificationsDisabled},
            success:  function(data) {
                swarm.review.updateReview(wrapper, data);
            },
            complete: callback
        });
    },

    runTest: function (id, callback) {
        var wrapper = $('.review-wrapper'),
            review  = wrapper.data('review');
        $.ajax({
            type: 'POST',
            url: '/api/v10/reviews/' + review.id + '/testruns/' + id + '/run',
            dataType: 'json',
            complete: callback
        });
    },

    editReviewersModal: function(review, callback) {
        var reviewers   = $.grep(review.participants, function(id) { return id !== review.author; }),
            details     = review.participantsData || {},
            modal       = $($.templates.editReviewersDialog.render()).appendTo('body'),
            disableMenu = function(button){
                button.attr('title', swarm.t('Voting requirements cannot be reduced.'))
                    .on('click',function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                    });
            };

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // Allow element flow to be controlled
        modal.on('limit', function(e) {
            if (e.target === this) {
                var $this = $(this);
                $this.removeClass('freeflow');
                if ($this.find('.reviewers-list').height() < 400) {
                    // There are too many reviewers, limit the height
                    $this.addClass('freeflow');
                }
            }
        });

        // setup multiPicker plugin for selecting reviewers
        var reviewersSelect = modal.find('.reviewer-multipicker');
        reviewersSelect.userMultiPicker({
            itemsContainer: modal.find('.reviewers-list'),
            selected:       reviewers.filter(function(item){
                var hasin = -1 === String(item).indexOf('swarm-group-');
                return hasin;
            }),
            selectedGroups: reviewers.filter(function(item){
                var hasin = -1 !== String(item).indexOf('swarm-group-');
                return hasin;
            }),
            inputName:       'reviewers',
            enableGroups:    true,
            useGroupKeys:    true,
            excludeProjects: true,
            excludeUsers:    [review.author],
            createItem:      function(value) {
                var label    = swarm.config.get('users.display_fullname') === 'true' && (typeof swarm.users === 'object' && typeof swarm.users[value] === 'string')
                    ? swarm.users[value]
                    : value;
                var item     = $($.templates(this.itemTemplate).render({
                    value: label, inputName: this.options.inputName, id: value
                }));

                item.find('.btn-group').addClass('minimum-'+(!!(details[value]) && details[value].minimumRequired)).prepend(
                    $.templates.requiredReviewerButton.render({
                        isRequired: !!(details[value]) && details[value].required,
                        minimumRequired: !!(details[value]) && details[value].minimumRequired,
                        value:      value,
                        isGroup:    String(value).indexOf('swarm-group-') !== -1
                    })
                );
                // Adjust the options for reviewers with a sticky minimum
                if (!!(details[value]) && details[value].minimumRequired) {
                    item.find('button.item-remove').remove();
                    if(details[value].minimumRequired === true && details[value].required === true) {
                        // Prevent this reviewer becoming optional
                        disableMenu(item.find('button.item-require'));
                    }
                }
                modal.trigger('limit');
                return item;
            }
        });

        // setup required reviewer click button listeners as last handler for the button
        modal.on('click.required', '.item-require.btn-info', function() {
            var $this = $(this);
            setTimeout(function() {
                var isRequired = $this.hasClass('active');

                $this.find('i').toggleClass('icon-star', isRequired).toggleClass('icon-star-empty', !isRequired);
                $this.find('input').prop('disabled', !isRequired);

                // temporarily show confirmation tooltip
                $this.attr('data-original-title', isRequired ? swarm.t('Vote Required') : swarm.t('Vote Optional') );
                $this.tooltip('show');

                // switch back to action verbiage shortly thereafter
                setTimeout(function() {
                    $this.attr('data-original-title', isRequired ? swarm.t('Make Vote Optional') : swarm.t('Make Vote Required'));
                }, 1000);
            }, 0);
        });
        modal.on('click.required', '.group-required a', function(e) {
            e.preventDefault();
            var option = $(this);
            var btnGroup = option.closest('.btn-group');
            var required = option.data('required');
            btnGroup.find('input.quorum').val(required);
            setTimeout(function() {
                var requiredButton = btnGroup.find('.item-require');

                requiredButton.find('i')
                    .removeClass('icon-star icon-star-empty')
                    .addClass(required ? 'icon-star' : 'icon-star-empty')
                    .text(1 === required ? required : '');

                // Adjust the dropdown for the current value
                btnGroup.find('li').show();
                btnGroup.find('li a[data-required='+required+']').parent().hide();
                // Set disabled status all inputs in this group
                btnGroup.find('input').prop('disabled', !required);
                btnGroup.find('.quorum').prop('disabled', required !== 1);
                // temporarily show confirmation tooltip
                requiredButton.attr('data-original-title', required ? required === 1 ? swarm.t('One Vote Required') : swarm.t('Vote Required') : swarm.t('Vote Optional') );
                requiredButton.tooltip('show');

                // switch back to action verbiage shortly thereafter
                if(requiredButton.length > 0 && required === true){
                    disableMenu(requiredButton);
                }
                setTimeout(function(){
                    if (required === true && requiredButton.hasClass('minimum-true')) {
                     requiredButton.attr('data-original-title', swarm.t('Voting requirements cannot be reduced.'));

                    } else {
                        requiredButton.attr('data-original-title', swarm.t('Change Required Votes'));
                    }
                    requiredButton.tooltip('hide');
                }, 1000);
            }, 0);
        });

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post('/reviews/' + review.id + '/reviewers', modal.find('form'), function(response) {
                if (callback && response.isValid) {
                    swarm.review.updateGroupsMembers(response.groupsMembership);
                    callback(modal, response);
                }
            }, modal.find('.messages')[0]);
        });

        // ensure the input is focused when we show
        modal.on('shown', function(e) {
            if (e.target === this) {
                var $this = $(this);
                $this.find('.reviewers input.multipicker-input').focus();
                $this.trigger('limit');
            }
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    openEditReviewersDialog: function(review, callback) {
        // make a call to the server to get a fresh copy of the reviewer
        $.ajax('/reviews/' + review.id, {
            dataType: 'json',
            data:      {format: 'json'},
            success:   function(data) {
                swarm.review.editReviewersModal(data.review, callback);
            }
        });
    },

    openEditDialog: function(data, currentUser, callback) {
        var modal = $($.templates(
            '<div class="modal hide fade review-edit" tabindex="-1" role="dialog" aria-labelledby="edit-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form">'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="edit-title">{{te:"Edit Description"}}</h3>'
            +       '</div>'
            +       '<div class="modal-body">'
            +           '<div class="messages"></div>'
            +           '<div class="control-group">'
            +               '<div class="controls">'
            +                   '<textarea name="description" class="border-box monospace"'
            +                       'placeholder="{{te:"Provide a description"}}" rows="15" cols="80" required>'
            +                       '{{>review.description}}'
            +                   '</textarea>'
            +               '</div>'
            +           '</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<div class="control-group buttons form-inline">'
            +               '<div class="controls pull-right">'
            +                   '{{if review.pending && currentUser.id === review.versions[0].user}}'
            +                   '<label class="update-original-changelist-checkbox" for="updateOriginalChangelist">'
            +                       '<input type="checkbox" id="updateOriginalChangelist" name="updateOriginalChangelist">'
            +                        swarm.te("Update original changelist")
            +                   '</label>'
            +                   '{{/if}}'
            +                   '<button type="submit" class="btn btn-primary">{{te:"Update"}}</button>'
            +                   '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
            +               '</div>'
            +           '</div>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render({review: data.review, currentUser: currentUser})).appendTo('body');

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post('/reviews/' + data.review.id, modal.find('form'), function(response) {
                if (!response.isValid) {
                    return;
                }

                callback(modal, response);
            }, modal.find('.messages')[0]);
        });

        // ensure the textarea is focused when we show
        modal.on('shown', function(e) {
            $(this).find('textarea').focus();
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    openTransitionDialog: function(state, data, callback) {
        // grab count of unverified actionable comments
        var openCount = $('#comments table.opened-comments tr.task-state-open').length;

        var modal = $($.templates(
            '<div class="modal hide fade review-transition" tabindex="-1" role="dialog" aria-labelledby="transition-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form" id="review-state-change-modal">'
            +       '<input type="hidden" name="state" value="{{>state}}">'
            +       '{{if review.commitStatus.error}}<input type="hidden" name="commitStatus" value="">{{/if}}'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="transition-title">{{if state == "approved:commit"}}' + swarm.te("Commit") + '{{else}}' + swarm.te("Update") + '{{/if}} {{te:"Review"}}</h3>'
            +       '</div>'
            +       '<div class="modal-body">'
            +           '<div class="messages">'
            +               '{{if (state === "approved:commit" || state === "approved") && openCount}}'
            +                   '<div class="alert open-count"><strong>' + swarm.te("Warning") + '!</strong> '
            +                       swarm.tpe("There is an open task on this review", "There are %s open tasks on this review", openCount)
            +                   '</div>'
            +               '{{/if}}'
            +           '</div>'
            +           '<div class="control-group">'
            +               '<textarea name="description" class="border-box{{if state == "approved:commit"}} monospace{{/if}}"'
            +                 '{{if state == "approved:commit"}} required rows="15" cols="auto">{{>review.description}}'
            +                 '{{else}} placeholder="' + swarm.te("Optionally, provide a comment") + '" rows="10" cols="80">{{/if}}'
            +               '</textarea>'
            +           '</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<div id="review-transition-control-group" class="control-group buttons form-inline">'
            +               '<div class="controls pull-right">'
            +                 '{{if cleanup && "user" === cleanup.mode && state === "approved:commit"}}'
            +                   '<label class="checkbox" for="cleanup-on-commit-{{>review.id}}">'
            +                       swarm.te("Remove pending changelists") + ' '
            +                       '<input type="checkbox" '
            +                           'id="cleanup-on-commit-{{>review.id}}" name="cleanup"'
            +                           '{{if cleanup && true === cleanup.default}} checked="checked"{{/if}}>'
            +                   '</label>'
            +                       '<a href="' + swarm.assetUrl('/docs/Content/Swarm/code_reviews.states.html#code_reviews.states.state_actions') + '"'
            +                       ' id="cleanup-on-commit-help-{{>review.id}}"'
            +                       ' target="_swarm_docs" title="' + swarm.te("Show documentation for this feature") + '">'
            +                       '<i class="icon-question-sign"></i></a>'
            +                 '{{/if}}'
            +                   '<button type="submit" class="btn btn-primary">{{te:transitions[state]}}</button>'
            +                   '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
            +               '</div>'
            +           '</div>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render({state: state, review: data.review, transitions: data.transitions, openCount: openCount, cleanup: data.cleanup, baseUrl: $('body').data('base-url')})).appendTo('body');

        // if committing, add sub-form for selecting jobs (only if there are jobs attached)
        if (state === 'approved:commit' && data.jobs.length) {
            // render jobs sub-form
            var jobsForm = $(
                '<div class="control-group jobs-list">'
                +   '<input type="hidden" name="jobs" value="">'
                +   '<table class="table"></table>'
                +   '<input type="hidden" name="fixStatus">'
                + '</div>'
            );
            $.each(data.jobs, function(){
                jobsForm.find('table').append($.templates(
                    '<tr data-job="{{>job}}">'
                    +   '<td>'
                    +     '<input type="checkbox" name="jobs[]" value="{{>job}}" checked="checked">'
                    +   '</td>'
                    +   '<td class="job-id">'
                    +     '<a href="{{:link}}" target="_blank">{{>job}}</a>'
                    +   '</td>'
                    +   '<td class="job-status">'
                    +     '{{te:status}}'
                    +   '</td>'
                    +   '<td class="job-description force-wrap" width="90%">'
                    +     '{{:description}}'
                    +   '</td>'
                    + '</tr>'
                ).render(this));
            });

            // expand descriptions
            jobsForm.find('.job-description').expander({slicePoint: 70});

            // place jobs list in the dialog
            modal.find('form .modal-body').append(jobsForm);

            // if job status field is defined, add drop-down for selecting fix status upon submit
            if (data.jobStatus) {
                // determine default job status:
                // - use default fix status from jobSpec preset (i.e. fixStatus
                //   if preset is in the form of 'jobStatus,fix/fixStatus')
                // - if no fixStatus, use 'closed'
                // - if neither of above is present, use the first option in the list
                var preset         = data.jobStatus['default'],
                    fixStatus      = preset.split(',fix/').pop(),
                    defaultStatus  = fixStatus !== preset ? fixStatus : 'closed';

                // prepare list with available job fix statuses for the drop-down
                var options = '<li data-status="same"><a href="#">' + swarm.te('Same') + '</a></li>'
                    + '<li class="divider"></li>';
                $.each(data.jobStatus.options, function(){
                    var status   = this.valueOf(),
                        selected = status === defaultStatus ? 'selected' : '';
                    options += $.templates(
                        '<li data-status="{{>status}}" class="{{:selected}}"><a href="#">{{:label}}</a></li>'
                    ).render({status: status, label: swarm.jobs.getFriendlyLabel(status), selected: selected});
                });

                // add drop-down to select job status
                modal.find('#review-transition-control-group').prepend($.templates(
                    '<div class="pull-left">'
                    +   '<span class="status-label">' + swarm.te("Job Status on Commit") + '</span>'
                    +   '<div class="btn-group status-dropdown">'
                    +     '<button type="button" class="btn dropdown-toggle" data-toggle="dropdown" aria-haspopup="true">'
                    +       '<span class="text"></span>'
                    +       ' <span class="caret"></span>'
                    +     '</button>'
                    +     '<ul class="dropdown-menu" role="menu" aria-label="' + swarm.te("Job Status on Commit") + '">'
                    +       '{{:options}}'
                    +     '</ul>'
                    +   '</div>'
                    + '</div>'
                ).render({options: options}));

                // prepare handler for updating fix status in drop-down button label and in form
                var updateJobStatus = function(){
                    var menu     = modal.find('.status-dropdown .dropdown-menu'),
                        selected = menu.find('.selected').length ? menu.find('.selected') : menu.find('li:first'),
                        status   = selected.data('status'),
                        label    = selected.find('a').html();

                    // update button label to match the selected option
                    modal.find('.status-dropdown .text').text(label);

                    // update fixStatus in form
                    jobsForm.find('[name="fixStatus"]').val(status);
                };
                updateJobStatus();

                // wire-up clicking on job status drop-down menu option
                modal.on('click.job.status', '.modal-footer .dropdown-menu a', function(e){
                    e.preventDefault();

                    // set the clicked option as the only selected
                    $(this).closest('.dropdown-menu').find('li').removeClass('selected');
                    $(this).closest('li').addClass('selected');

                    updateJobStatus();
                });
            }
        }

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.overlay.show();
            swarm.form.post('/reviews/' + data.review.id + '/transition', modal.find('form'), function(response) {
                if (!response.isValid) {
                    return;
                }
                callback(modal, response);
            }, modal.find('.messages')[0]);
        });

        // ensure the textarea is focused when we show
        modal.on('shown', function(e) {
            $(this).find('textarea').focus();
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    openEditAuthorDialog: function (review) {
        var wrapper = $('.review-wrapper'),
            modal   = $($.templates(
                '<div class="modal hide fade author-edit" tabindex="-1" role="dialog" aria-labelledby="edit-title" aria-hidden="true">'
                +   '<form method="post" class="form-horizontal modal-form">'
                +       '<div class="modal-header">'
                +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
                +           '<h3 id="edit-title">{{te:"Edit Author"}}</h3>'
                +       '</div>'
                +       '<div class="modal-body">'
                +           '<div class="messages"></div>'
                +           '<div class="input-prepend" clear="both">'
                +               '<span class="add-on">'
                +                   '<i class="icon-user"></i>'
                +               '</span>'
                +               '<input type="text" name="user" class="input-xlarge multipicker-input" placeholder="' + swarm.te('Author Name') + '">'
                +           '</div>'
                +       '</div>'
                +       '<div class="modal-footer">'
                +           '<button type="submit" class="btn btn-primary">{{te:"Save"}}</button>'
                +           '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
                +       '</div>'
                +   '</form>'
                + '</div>'
            ).render()).appendTo('body');

        //todo remove current author
        modal.find('.multipicker-input').userMultiPicker({
            onSelect: function() {
                var active = this.typeahead.$menu.find('.active');
                if (active.length) {
                    this.$element.val(active.data('value').id);
                }
            },
            excludeUsers:   [String(review.author)]
        });

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post('/reviews/' + review.id + '/author', modal.find('form'), function(data) {
                if (data.isValid) {
                    swarm.review.updateReview(wrapper, data);
                    modal.modal('hide');
                    return false;
                }
            }, modal.find('.messages')[0]);
        });

        // ensure the input is focused when we show
        modal.on('shown', function(e) {
            $(this).find('input[type=text]').focus();
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    },

    // polls the review with a 1 second delay between requests,
    // calling the callback function after the success of each
    // request until the callback returns === false
    _polling: null,
    pollForUpdate: function(callback) {
        window.clearTimeout(swarm.review._polling);
        swarm.review._polling = setTimeout(function() {
            var review  = $('.review-wrapper').data('review');
            // make request
            $.ajax('/reviews/' + review.id, {
                dataType:   'json',
                data:       {format:'json'},
                success:    function(data) {
                    if (callback && callback(data.review) !== false) {
                        swarm.review.pollForUpdate(callback);
                    } else {
                        swarm.overlay.hide();
                    }
                },
                error:      function(data) {
                    swarm.overlay.hide();
                }
            });
        }, 1000);
    },

    updateReview: function(wrapper, response){
        // update data on the wrapper
        wrapper.data('review',              response.review);
        wrapper.data('avatars',             response.avatars);
        wrapper.data('author-avatar',       response.authorAvatar);
        wrapper.data('transitions',         response.transitions);
        wrapper.data('can-edit-reviewers',  response.canEditReviewers);
        wrapper.data('can-edit-author',     response.canEditAuthor);
        wrapper.data('change-modify',       response.changeModify);

        // update author info
        swarm.review.initAuthor();

        // Update the revision version select inputs
        swarm.review.initVersionChooser();

        // update the slider
        swarm.review.initSlider();

        // update description and re-initialize for edit (as response doesn't contain edit button)
        wrapper.find('.change-description').html(response.description);
        swarm.review.initEdit();

        // rebuild state menu
        swarm.review.buildStateMenu();

        // update test status
        swarm.review.updateTestStatus();

        // update deploy status
        swarm.review.updateDeployStatus();

        // update reviewers area
        swarm.review.buildReviewers();

        // update the Add/Change control
        swarm.review.buildAddChangeMenu();
    },

    updateTestStatus: function (onSuccess) {
        var wrapper = $('.review-wrapper'),
            header  = wrapper.find('.review-header'),
            review  = wrapper.data('review'),
            selectedRevision = $('select#revision-select-to').val(),
            iconValues;
        $.ajax({
            url: '/api/v10/reviews/' + review.id + '/testruns?version=' + selectedRevision,
            dataType: 'json',
            success: function(result) {
                // if we have an existing status icon, destroy it.
                header.find('.test-status').remove();
                if (result.data.testruns !== undefined && result.data.testruns.toString().length > 0) {
                    iconValues = swarm.review.getTestIconValues(result.data.status);
                    header.find('.review-status').prepend(swarm.review.buildTestRunMenu(result.data.testruns, iconValues));
                } else {
                    iconValues = swarm.review.getTestIconValues(review.testStatus);
                    header.find('.review-status').prepend(swarm.review.buildTestStatusButton(review, iconValues));
                }
                if (onSuccess) {
                    onSuccess();
                }
            }
        });
    },

    getTestIconValues: function (status) {
        var color,
            icon,
            statusText;
        switch (status) {
            case 'running':
            case 'started':
                color      = 'clear';
                icon       = 'svg-refresh animate';
                statusText = swarm.t('Tests Started');
                break;
            case 'fail':
                color      = 'danger';
                icon       = 'warning-sign icon-white';
                statusText = swarm.t('Tests Failed');
                break;
            case 'pass':
                statusText = swarm.t('Tests Pass');
                color      = 'success';
                icon       = 'check icon-white';
                break;
            default:
                statusText = swarm.t('Tests Not Started');
                color      = '';
                icon       = '';
                break;
        }
        return {'color': color, 'icon': icon, 'statusText': statusText};
    },

    buildTestRunMenu: function (testruns, iconValues) {
        var items = "",
            buttonTemplate =
                '<span class="pull-left test-status">'
                + '<a class="dropdown-toggle btn btn-small btn-{{:color}}" data-toggle="dropdown">'
                + '<i class="icon-{{:icon}}"></i>'
                + '</a>'
                + '</span>';
        var button = $($.templates(buttonTemplate).render({icon: iconValues.icon, color: iconValues.color}));
        var topRevision = parseInt($('select#revision-select-to option').length, 10);
        var selectedRevision = parseInt($('select#revision-select-to').val(), 10);
        var isTopRevision = topRevision === selectedRevision;
        var runTestText = swarm.t('Run test');
        var rerunTestText = swarm.t('Rerun test');
        var user = swarm.user.getAuthenticatedUser();

        // rerun test when button is clicked, then refresh the page
        $(document).on('click', '.button-tests-rerun', function(e) {
            var element = e.currentTarget;
            if (!$(element).hasClass('disabled') && user) {
                $('.button-tests-rerun').addClass("disabled");
                $('.button-tests-rerun').children('i').attr('class', 'icon-tests-rerun-disabled');
                $(element).children('i').html('<span class="swarm-icon icon-tests-waiting animate"></span>');
                var id = parseInt(element.id, 10);
                var callback = function () {
                    swarm.review.updateTestStatus(function () {
                        $('.tooltip').remove(); // Remove the "Rerun tests" tooltip when test status container is removed
                        $('.test-status').addClass('open'); // Open the dropdown again
                        $('.dropdown-menu').addClass('pull-right'); // Align the dropdown again
                    });
                };
                swarm.review.runTest(id, callback);
            }
            e.stopPropagation();
        });

        $.each(testruns, function () {
            var testrun  = this,
                projectTest = testrun.test.startsWith("project:"),
                testRunIconValues = swarm.review.getTestIconValues(testrun.status);
            var rerunButton = (!user || !isTopRevision || testrun.status === 'running' || projectTest) ? ''
                : '<button type="button" title="{{>rerun}}" class="button-tests-rerun" id="{{>id}}"><i class="icon-tests-rerun">'
                + '<svg class="svgIcon"><use href="/swarm/img/icons/line/triangle-circle.svg#Combined-Shape_1_"></use></svg></i></button>';
            items += $.templates(
                '<li class="dropdown-row"><a class="testrun" href="{{:url}}" target="{{>target}}" id="testrun-{{>id}}" data-name="{{>test}}" data-state="testrun-{{>id}}">'
                + '<p title="{{>text}}"><i class="icon-{{:icon}} btn-{{:color}}"></i>{{>text}}</p>'
                + '</a>' + rerunButton + '</li>'
            ).render({
                text: (testrun.title || testrun.test) + (testrun.messages ? ': ' + testrun.messages : ''),
                id: testrun.id,
                url: testrun.url || "javascript".concat(":void(0)"),
                icon: testRunIconValues.icon,
                color: testRunIconValues.color,
                test: testrun.test,
                target: (testrun.url ? '_blank' : ''),
                rerun: testrun.status === 'notstarted' ? runTestText : rerunTestText
            });
        });
        if (items.length !== 0) {
            var menu = button.append('<ul class="dropdown-menu"></ul>').find('ul');
            menu.children().remove();
            menu.append(items);
        }
        return button;
    },

    buildTestStatusButton: function (review, iconValues) {
        // if test status is null, nothing to show.
        if (!review.testStatus) {
            return;
        }
        var details = review.testDetails,
            testUrl = details.url      ?  encodeURI(details.url) : '',
            endTime = details.endTimes && details.endTimes.length
                ? Math.max.apply(null, details.endTimes)
                : null;
        var button = $(testUrl ? '<a>' : '<span>');
        button.addClass('test-status pull-left btn btn-small btn-' + iconValues.color)
            .attr('href', testUrl)
            .attr('target', '_blank')
            .attr('disabled', !testUrl)
            .append($('<i>').addClass('icon-' + iconValues.icon));


        // build the tooltip title as an object so that we can have dynamic timeago
        var title = $('<span>');
        title.text(iconValues.statusText);
        if (endTime) {
            title.append('<br>')
                .append('<span class="timeago muted" title="' + new Date(endTime * 1000).toISOString() + '"></span>')
                .find('.timeago').formatTimestamp();
        }
        button.tooltip({title: title});
        return button;
    },

    updateDeployStatus: function(){
        var wrapper = $('.review-wrapper'),
            header  = wrapper.find('.review-header'),
            review  = wrapper.data('review'),
            success = review.deployStatus === 'success',
            deploying = 'deploying' === review.deployStatus,
            title   = success
                ? swarm.te('Try it out!')
                : deploying
                    ? swarm.te('Deploying...')
                    : swarm.te(
                        'Deploy Failed'
                        + (review.deployStatus
                            ? review.deployStatus.replace('failed:','\n')
                            : '')
                    ),
            color   = success || deploying ? '' : 'warning',
            url     = review.deployDetails.url ? encodeURI(review.deployDetails.url) : '',
            htmlTag = url ? 'a' : 'span';

        // if deploy status is null or its success but we lack a url, nothing to show.
        if (!review.deployStatus || (success && !url)) {
            return;
        }

        // if we have an existing status icon, destroy it.
        header.find('.deploy-status').remove();

        header.find('.review-status').prepend(
            '<' + htmlTag + ' class="deploy-status pull-left btn btn-small btn-' + color + '" '
            +   'title="' + title + '" '
            +   (url && !deploying ? 'href="' + url + '" target="_blank"' : 'disabled="disabled"')
            + '><i class="' + (deploying ? 'icon-svg-refresh animate' : 'icon-plane') + (success ? '' : ' icon-white') + '"></i></' + htmlTag + '>'
        );
    },

    buildAddChangeMenu: function() {
        var wrapper      = $('.review-wrapper'),
            button       = wrapper.find('.add-change'),
            review       = wrapper.data('review'),
            changeModify = wrapper.data('change-modify'),
            btnText = '';

        button.toggleClass('add-change-hide', !changeModify);
        if (changeModify) {
            var items = "";
            if (review.pending) {
                btnText = swarm.te("Append Pending Changelist");
                items += $.templates(
                    '<li><a href="#" id="change-append" data-state="change-append">'
                    + '<i class="icon-plus"></i>{{>text}}'
                    + '</a></li>'
                ).render({text: btnText});
            }
            btnText = swarm.te("Replace with Pending Changelist");
            items += $.templates(
                '<li><a href="#" id="change-replace-pending" data-state="change-replace-pending">'
                + '<i class="icon-share-alt"></i>{{>text}}'
                + '</a></li>'
            ).render({text: btnText});

            btnText = swarm.te("Replace with Committed Changelist");
            items += $.templates(
                '<li><a href="#" id="change-replace-committed" data-state="change-replace">'
                + '<i class="icon-share-alt"></i>{{>text}}'
                + '</a></li>'
            ).render({text: btnText});

            if (items.length !== 0) {
                var menu = button.append('<ul class="dropdown-menu"></ul>').find('ul');
                menu.children().remove();
                menu.append(items);
            }
        }
    },

    buildStateMenu: function(){
        var wrapper     = $('.review-wrapper'),
            header      = wrapper.find('.review-header'),
            review      = wrapper.data('review'),
            transitions = wrapper.data('transitions');

        // if we have an existing state menu, destroy it
        header.find('.state-menu').remove();

        // render menu options individually - jsrender can't iterate objects
        // (see: https://github.com/BorisMoore/jsrender/issues/40)
        var items = "";
        $.each(transitions, function(state, label) {
            items += $.templates(
                '<li><a href="#" data-state="{{>state}}">'
                + ' <i class="swarm-icon icon-review-{{class:state}}"></i> {{te:label}}'
                + '</a></li>'
            ).render({state: state, label: label});
        });

        // Check if the config allows author to obliterate and then
        // Check if the user logged in is the author.
        // Otherwise if user is admin or super allow them to see the button.
        var body                    = $('body');
        var allowAuthorObliterate = swarm.config.get('reviews.allow_author_obliterate');
        var obliterateState = "obliterate-review";
        var obliterateLabel = swarm.te("Obliterate Review");
        if ((allowAuthorObliterate === 'true' && body.data('user') && review.author === body.data('user').id) || body.hasClass('admin')) {
            items += $.templates(
                '<li class="divider"></li>'
                + '<li><a href="#" data-state="{{>state}}">'
                + ' <i class="icon-remove-sign"></i> {{>label}}'
                + '</a></li>'
            ).render({state: obliterateState, label: obliterateLabel});
        }

        var tooltip = (review.commitStatus.error) ? review.commitStatus.error.split('\n')[0] : "";
        header.find('.review-status').append(
            $.templates(
                '<div class="state-menu btn-group pull-right">'
                + ' <button type="button"'
                + '  class="btn btn-small btn-primary btn-branch dropdown-toggle '
                + '         {{if review.commitStatus.error}}btn-danger{{/if}}"'
                + '  {{if !authenticated || transitions === false}}disabled{{else}}aria-haspopup="true"{{/if}}'
                + '  {{if review.commitStatus.error}}'
                + '    title="{{te:"Error committing"}} {{te:tooltip}}"'
                + '  {{/if}}'
                + '  data-toggle="dropdown">'
                + '{{if review.commitStatus.error}}'
                + '  <i class="icon-white icon-warning-sign"></i>'
                + '    {{te:"Error"}}'
                + '{{else review.commitStatus.start}}'
                + '  <i class="swarm-icon icon-white icon-committed"></i>'
                + '    {{if review.commitStatus.status}}{{te:review.commitStatus.status}}{{else}}{{te:"Committing"}}{{/if}}...'
                + '{{else !review.pending && review.state=="approved"}}'
                + '  <i class="swarm-icon icon-white icon-committed"></i>'
                + '    {{te:review.stateLabel}}'
                + '{{else}}'
                + '  <i class="swarm-icon icon-white icon-review-{{>review.state}}"></i>'
                + '    {{te:review.stateLabel}}'
                + '{{/if}}'
                + ' <span class="caret"></span>'
                + '</button>'
                + ' <ul class="dropdown-menu" role="menu" aria-label="{{te:"Transition Review"}}">{{:items}}</ul>'
                + '</div>'
            ).render({review: review, items: items, transitions: transitions, authenticated: $('body').is('.authenticated'), tooltip: tooltip})
        );
    },

    add: function(button, change) {
        var btn = $(button);

        // Prevent multiple calls to the API when the button is repeatedly and quickly clicked
        // This seems simpler than trying to remove and reattach the listener.
        if (btn.data('executing')) {
            return;
        }
        btn.data('executing', true);

        change = change || btn.closest('.change-wrapper').data('change').id;
        $('div.alert').remove();

        // disable button while talking to the server.
        swarm.form.disableButton(btn);

        $.post('/reviews/add', {change: change}, function(response) {
            if (response.id) {
                swarm.form.enableButton(btn);

                // convert to a 'view review' button.
                btn
                    .prop('onclick', null).off('click')
                    .attr('href', swarm.url('/reviews/' + response.id))
                    .toggleClass('btn-primary btn-success')
                    .find('.text').text(swarm.t('View Review'));

                // for change tables, update the review status icon
                btn.closest('tr').find('td.review-status').append(
                    $('<i>').addClass('swarm-icon icon-review-needsReview')
                        .attr('title', swarm.te('Needs Review'))
                );

                // indicate success via a temporary tooltip.
                btn.tooltip({title: swarm.t('Review Requested'), trigger: 'manual'}).tooltip('show');
                setTimeout(function(){
                    btn.tooltip('destroy');
                }, 3000);
            } else if (!response.isValid) {
                swarm.form.enableButton(btn);
                if (response.error) {
                    swarm.alert.popup(response.error, 'error');
                }
            }

            // Once the results of the API call have been processed, free the button up to execute another API call
            btn.removeData('executing');
        });

        return false;
    },

    sendDelayedNotifications: function (button) {
        button = $(button);
        // Get the topic of the review and pass to post
        var topic  = button.data('topic');
        $.post('/api/v9/comments/notify', {topic: topic}, function(response){
            if (response.code === 200) {
                swarm.alert.popup(response.message, 'success');
                $('.post-and-send-delayed-comments .post-count').text(' ' + swarm.t('Post and Notify (0)'));
            } else {
                swarm.alert.popup(response.error, 'error');
            }
        });
    },

    setFloat: function () {
        var leftTop = $("#review-actionable-items").position().top;
        var rightTop = $("#reviewers-summary").position().top;
        if (Math.abs(leftTop - rightTop) > 50) {
            $('#review-actionable-items').css('float', 'left');
            $('#reviewers-summary').css('float', 'none').css('display','inline-table').css('padding-left','12px');
        } else {
            $('#review-actionable-items').css('float', 'right');
            $('#reviewers-summary').css('float', 'right').css('display','block').css('padding-left','inherit');
        }
    },

    openChangeSelectorModelCallback: function(modal, response) {
        // keep the modal dialog disabled
        var changeId = $(modal).find('.change-input input').val();
        $(modal).find('.changes-list, input').addClass('disabled').prop('disabled', true);
        swarm.form.disableButton($(modal).find('[type=submit]'));

        // prevent user from closing the dialog now
        $(modal).on('hide', function(e) {
            e.preventDefault();
        });

        // start polling until review is ready
        swarm.review.pollForUpdate(function(review) {
            if (!review.commits) {
                return;
            }
            // Need to check if the Change ID is in the changes or in the commits.

            var i;
            for (i = 0; i < review.commits.length; i++) {
                if (parseInt(review.commits[i], 10) === parseInt(changeId, 10)) {
                    window.location.reload();
                    return false;
                }
            }
            var j;
            for (j = 0; i < review.changes.length; j++) {
                if (parseInt(review.changes[j], 10) === parseInt(changeId, 10)) {
                    window.location.reload();
                    return false;
                }
            }
        });
    },

    openChangeSelector: function(url, data, callback, status, title, state, mode) {
        data.review.path = data.change.basePath || '';
        // Put the review data into its own var to enable adding addition data to be used in the html template.
        var mergedData         = data.review;
        mergedData.dialogTitle = title;
        mergedData.dialogState = state;
        mergedData.dialogMode  = mode;
        mergedData.dialogSubTitle = (status === "pending" ? swarm.te("Pending Changes") : swarm.te("Commits"));

        var modal = $($.templates(
            '<div class="modal hide fade select-change-modal" tabindex="-1" role="dialog" '
            +      'aria-labelledby="select-change-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form">'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="select-change-title">{{>dialogTitle}}</h3>'
            +       '</div>'
            +       '<div class="modal-body">'
            +           '<input type="hidden" name="id" value="{{>id}}">'
            +           '<input type="hidden" name="state" value="{{>dialogState}}">'
            +           '<input type="hidden" name="mode" value="{{>dialogMode}}">'
            +           '<div class="messages"></div>'
            +           '<div class="change-input control-group pad2 padw0">'
            +               '<div class="input-prepend">'
            +                   '<span class="add-on">@</span>'
            +                   '<input class="input-small" type="text" pattern="[0-9]*" name="change" id="change" '
            +                          'required placeholder="' + swarm.te("Change") + '">'
            +               '</div>'
            +           '</div>'
            +           '<div class="clearfix">'
            +               '<h4 class="muted">{{>dialogSubTitle}}</h4>'
            +               '<input class="path pull-left border-box" type="text" name="path" '
            +                      'placeholder="' + swarm.te("Path") + '" value="{{>path}}">'
            +               '<input class="user pull-right border-box" type="text" name="user" '
            +                      'placeholder="' + swarm.te("User") + '" value="{{>author}}">'
            +           '</div>'
            +           '<div class="changes-list">'
            +               '<div class="loading animate muted pad3">' + swarm.te("Loading...") + '</div>'
            +           '</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<button type="submit" class="btn btn-primary">' + swarm.te("Select") + '</button>'
            +           '<button type="button" class="btn" data-dismiss="modal">' + swarm.te("Cancel") + '</button>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render(mergedData)).appendTo('body');

        // open dialog (auto-width, centered)
        swarm.modal.show(modal);

        swarm.form.checkInvalid($(modal).find('form'));

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post(url, modal.find('form'), function(response) {
                if (!response.isValid) {
                    return;
                }

                callback(modal, response);
            },
                modal.find('.messages')[0]);
        });

        // update change field when user clicks on a change table row
        modal.find('.changes-list').on('click', 'tr', function(e) {
            // allow user to middle-click/right-click so they can still
            // open the links if they want
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();

            // only update the value when the change-list isn't disabled
            if (!$(this).closest('.changes-list').is('.disabled')) {
                modal.find('#change').val(parseInt($(this).find('td.change a').text(), 10)).trigger('change');
                modal.find('.changes-list tr').removeClass('active');
                $(this).addClass('active');
            }
        });

        // clear active row if user edits change field manually
        modal.find('#change').on('input keyup blur', function(){
            modal.find('.changes-list tr').removeClass('active');
        });

        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });

        // change the changes markup after it loads to removing loading text and better fit the modal
        modal.find('.changes-list').on('changes-loaded', function() {
            $(this).find('.loading').remove();
            $(this).find('thead, td.time').remove();
            $(this).find('.description').expander('destroy').expander({slicePoint: 70});
        });

        // load a list of changes from the server
        swarm.changes.load(
            modal.find('input.path').val(),
            modal.find('.changes-list'),
            { user: modal.find('input.user').val(), status: status }
        );

        // try to load more when user scrolls to the bottom
        modal.find('.changes-list').scroll(function() {
            var list = modal.find('.changes-list');
            if ($.isScrolledToBottom(list, list.find('>table'))) {
                swarm.changes.load(
                    modal.find('input.path').val(),
                    modal.find('.changes-list'),
                    { user: modal.find('input.user').val(), status: status }
                );
            }
        });

        // reload changes if path or user changes
        modal.find('input.path, input.user').on(
            'input keyup blur',
            function(){
                clearTimeout(swarm.changes.filterTimeout);
                swarm.changes.filterTimeout = setTimeout(function(){
                    swarm.changes.load(
                        modal.find('input.path').val(),
                        modal.find('.changes-list'),
                        { user: modal.find('input.user').val(), reset: true, status: status }
                    );
                }, 500);
            }
        );
    },

    obliterate: function(data) {
        var callback = function(modal, response) {
            if (response.code === 200) {
                modal.modal('hide');
                window.location = swarm.url("/reviews");
            } else {
                swarm.overlay.hide();
                return false;
            }
        };
        var message  = swarm.te("Are you sure you want to Obliterate this Review?");
        var title    = swarm.te("Obliterate Review");
        var modal    = $($.templates(
            '<div class="modal hide fade review-obliterate" tabindex="-1" role="dialog" aria-labelledby="edit-title" aria-hidden="true">'
            +   '<form method="post" class="form-horizontal modal-form force-wrap">'
            +       '<div class="modal-header">'
            +           '<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>'
            +           '<h3 id="edit-title">{{>title}}</h3>'
            +       '</div>'
            +       '<div class="modal-body obliterate">'
            +           '<div class="messages error">{{>message}}</div>'
            +       '</div>'
            +       '<div class="modal-footer">'
            +           '<button type="submit" class="btn btn-primary" data-review="{{>id}}">{{te:"OK"}}</button>'
            +           '<button type="button" class="btn" data-dismiss="modal">{{te:"Cancel"}}</button>'
            +       '</div>'
            +   '</form>'
            + '</div>'
        ).render({id: data.review.id, message: message, title: title})).appendTo('body');

        // show dialog (auto-width, centered)
        swarm.modal.show(modal);

        // form submit
        modal.find('form').submit(function(e) {
            e.preventDefault();
            swarm.overlay.show(true);
            swarm.form.post('/api/v9/reviews/' + data.review.id + '/obliterate', modal.find('form'), function(response) {
                    if (!response.isValid) {
                        swarm.overlay.hide();
                        return;
                    }
                callback(modal, response);
            }, modal.find('.messages')[0],
            function(form) {
                var reviewID = $(form.find('button[type="submit"]')[0]).data('review');
                return {'review': reviewID};
            }

            );
        });
        // clean up on close
        modal.on('hidden', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    }
};

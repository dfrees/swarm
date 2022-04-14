/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

swarm.activity = {
    _loading: false,

    // Effective static definition of msgIds to be picked up by
    // i18n generation. Never needs to be called
    msgIds: function() {
        swarm.te('addressed an issue on');
        swarm.te('approved');
        swarm.te('archived');
        swarm.te('changed author of');
        swarm.te('cleared an issue on');
        swarm.te('cleared their vote on');
        swarm.te('commented on');
        swarm.te('committed');
        swarm.te('created');
        swarm.te('disabled notifications on');
        swarm.te('re-enabled notifications on');
        swarm.te('edited a comment on');
        swarm.te('edited reviewers on');
        swarm.te('joined');
        swarm.te('left');
        swarm.te('liked a comment on');
        swarm.te('made their vote optional on');
        swarm.te('made their vote required on');
        swarm.te('made themselves optional on');
        swarm.te('made themselves required on');
        swarm.te('modified');
        swarm.te('opened an issue on');
        swarm.te('rejected');
        swarm.te('released');
        swarm.te('reopened an issue on');
        swarm.te('reported');
        swarm.te('requested');
        swarm.te('requested further review of');
        swarm.te('requested revisions to');
        swarm.te('submitted');
        swarm.te('updated description of');
        swarm.te('updated files in');
        swarm.te('updated');
        swarm.te('voted down');
        swarm.te('voted up');
        swarm.te('review %s');
        swarm.te('passed tests for');
        swarm.te('failed tests for');
        swarm.te('running tests for');
        swarm.te('verified an issue on');
    },

    init: function(stream) {
        // honor user's type filter preference
        // select by attr as the stream may contain special characters such as period
        // that cause issue when doing a simple class selection
        var table = $('table.activity-stream').filter(function (index, item) {
            return $(item).attr('data-stream') !== undefined;
        }).first();
        var type  = swarm.localStorage.get('activity.type');
        if (type) {
            table.find('th .nav-pills a.type-' + type).closest('li').addClass('active');
        }

        // wire up stream type filter buttons.
        table.find('th .nav-pills a').click(function(){
            var type   = $(this).attr('class').match(/type-([\w]+)/).pop();
            var active = $(this).closest('li').is('.active');

            $(this).closest('.nav-pills').find('li').removeClass('active');
            $(this).closest('li').toggleClass('active', !active);
            swarm.activity.load(stream, true);

            // remember the user's selection.
            swarm.localStorage.set('activity.type', !active ? type : null);

            return false;
        });

        // wire-up activity dropdown to to switch between all/personal-activity
        table.find('ul.dropdown-menu a').on('click', function (e) {
            e.preventDefault();
            var scope = $(this).closest('li').data('scope');
            swarm.localStorage.set('activity.scope', scope);
            swarm.activity.load(stream, true);
        });

        // prevent from opening a dropdown when clicked on dropdown-toggle which
        // is not inside a dropdown as we always put a dropdown menu in the markup
        table.find('.dropdown-toggle').on('click', function (e) {
            if (!$(this).closest('.dropdown').length) {
                e.stopPropagation();
            }
        });

        // update activity table when user logs in
        $(document).on('swarm-login', function () {
            swarm.activity.load(stream, true);
        });
    },

    load: function(stream, reset, deficit) {
        if (swarm.activity._loading) {
            if (!reset) {
                return;
            }

            swarm.activity._loading.abort();
            swarm.activity._loading = false;
        }

        // find the appropriate table based on the contents of .data('stream')
        var table = $('table.activity-stream');
        if (deficit !== undefined || !table.length || table.data('stream').trim() !== 'stream-' + (stream || 'global')) {
            return;
        }

        // if reset requested, clear table contents
        if (reset) {
            table.data('last-seen',   null);
            table.data('end-of-data', null);
            table.find('tbody.activity-table').empty();
        }

        // if there are no more activity records, nothing else to do
        if (table.data('end-of-data')) {
            return;
        }

        // add extra row indicating that we are loading data
        // row is initially hidden, shown after 2s or as soon as we detect a 'deficit'
        table.find('tbody.activity-table').append(
              '<tr class="loading muted hide">'
            + ' <td colspan="3">'
            + '  <span class="loading">' + swarm.te('Loading...') + '</span>'
            + ' </td>'
            + '</tr>'
        );
        setTimeout(function(){
            table.find('tbody.activity-table tr.loading').removeClass('hide').find('.loading').addClass('animate');
        }, deficit === undefined ? 2000 : 0);

        // tweak table for authenticated user
        var originalStream = stream,
            scope          = swarm.localStorage.get('activity.scope') || 'user',
            user           = swarm.user.getAuthenticatedUser(),
            isSwitchable   = table.is('.switchable') && user !== null,
            isPersonal     = isSwitchable && scope === 'user';

        // change stream to personal if in personal view
        if (isPersonal) {
            stream = 'personal-' + user.id;
            table.addClass('stream-' + stream);
        }

        // apply type filter
        // the data-type-filter trumps the filter buttons
        // if data-type-filter is set, the filter buttons are disabled
        var type  = table.data('type-filter');
        if (type === null) {
            type  = table.find('th .nav-pills li.active a');
            type  = type.length && type.attr('class').match(/type-([\w]+)/).pop();
        }

        // only load activity older than the last loaded row
        var last = table.data('last-seen');

        // prepare urls for activity stream
        var url  = '/activity' + (stream ? '/streams/' : '');

        var max  = 50;
        swarm.activity._loading = $.ajax({
            url:        '/api/v9/activity/',
            data:       {stream: stream, max: max, after: last, type: type || null, disableHtml: false},
            dataType:   'json',
            success:    function(data){
                table.find('tbody.activity-table tr.loading').remove();

                // update last-seen data on table
                // if the last-seen id we received is null or same as the one from previous request,
                // set 'end-of-data' to indicate there are no more activity to fetch
                if (data.lastSeen === null || data.lastSeen === table.data('last-seen')) {
                    table.data('end-of-data', true);
                }

                table.data('last-seen', data.lastSeen);
                data = data.activity;

                // we cancel and reload in global view if this is default personal view
                // and user's personal stream is empty
                if (!swarm.localStorage.get('activity.scope') && isPersonal && !data.length) {
                    swarm.localStorage.set('activity.scope', 'all');
                    swarm.activity._loading = false;
                    swarm.activity.load(originalStream, true);
                    return;
                }

                // update title if this is a switchable stream
                // we can do this now because we know we are going to stay on this stream
                if (isSwitchable) {
                    table.find('thead .activity-title').removeClass('default-title').text(
                        isPersonal ? swarm.t('Followed Activity') : swarm.t('All Activity')
                    );
                }

                // enable dropdown if user is logged in
                table.find('thead .activity-dropdown').toggleClass('dropdown', isSwitchable);

                // add 'stream-personal' class to the table if user is logged in
                table.toggleClass('stream-personal', isPersonal);

                // update rss link url
                url = url.substr(-1) !== '/' ? url+'/' : url;
                if(stream === undefined){
                    table.find('a.rss-link').attr('href', swarm.url(url + 'rss'));
                } else {
                    table.find('a.rss-link').attr('href', swarm.url(url + 'rss?stream=' + stream));
                }
                // if we have no activity to show and there is no more on the server, let the user know
                if (!table.find('tbody.activity-table tr').length && !data.length && table.data('end-of-data')) {
                    table.find('tbody.activity-table').append($(
                        '<tr class="activity-info"><td><div class="alert border-box pad3">'
                      + (type ? swarm.te('No matching activity.') : swarm.te('No activity.'))
                      + '</div></td></tr>'
                    ));
                }

                var html;
                $.each(data, function(key, event) {
                    event.fullname = swarm.config.get('users.display_fullname') === 'true';
                    // prepare comment link
                    var count      = event.comments[0];
                    event.comments = {
                        count:   count,
                        label:   count
                            ? swarm.tp('%s comment', '%s comments', count)
                            : swarm.t('Add a comment'),
                        title:   event.comments[1] ? swarm.tp('%s archived', null, event.comments[1]) : "",
                        url:     event.url + (event.url && !event.url.match('#') ? '#comments' : '')
                    };
                    event.condensed = table.is('.condensed');
                    event.rowClass  = (event.topic  ? 'has-topic ' : '')
                                    + (event.type   ? 'activity-type-'   + event.type + ' ' : '')
                                    + (event.action ? 'activity-action-' + event.action.toLowerCase().replace(/ /g, '-') : '');

                    // attempt to localize some common server-side strings
                    var targetMatch = event.target.match(/^(\w+) (\d+)(?: \(revision (\d+)\))?(?: \((.+), line (\d+)\))?$/) || [];
                    if (targetMatch[1] === 'review' || targetMatch[1] === 'change') {
                        event.target             = swarm.te(targetMatch[1]) + ' %s';
                        event.targetReplacements = targetMatch.slice(2);

                        if (event.targetReplacements[1]) {
                            event.target += ' (' + swarm.te('revision') + ' %s)';
                        } else {
                            // remove from the array for any older comments that do not have revision so the text
                            // is still built correctly
                            event.targetReplacements.splice(1, 1);
                        }
                        if (event.targetReplacements[2]) {
                            event.target += ' (%s, ' + swarm.te('line') + ' %s)';
                        }
                    } else {
                        event.target = event.target.replace('review', swarm.te('review'));
                        event.target = event.target.replace('Review', swarm.te('Review'));
                        event.target = event.target.replace('change', swarm.te('change'));
                    }

                    html = $.templates(
                          '<tr id="{{>id}}" class="row-main {{>rowClass}}">'
                        +   '<td rowspan="2" width=64>{{:avatar}}</td>'
                        +   '<td class="activity-body">'
                        +     '{{if !condensed}}'
                        +       '<small class="pull-right"><span class="timeago muted" title="{{>date}}"></span></small>'
                        +     '{{/if}}'
                        +     '{{if user}}'
                        +       '{{if userExists}}<a alt="{{:userFullName}}" title="{{:userFullName}}" href="{{url:"/users"}}/{{url:user}}">{{/if}}'
                        +       '<strong>{{if fullname}}{{:userFullName}} {{else}}{{>user}}{{/if}}</strong>'
                        +       '{{if userExists}}</a>{{/if}} '
                        +     '{{/if}}'
                        +     '{{if behalfOf}}'
                        +     ' ({{te:"on behalf of"}} '
                        +       '{{if behalfOfExists}}<a alt="{{:behalfOfFullName}}" title="{{:behalfOfFullName}}" href="{{url:"/users"}}/{{url:behalfOf}}">{{/if}}'
                        +       '<strong>{{>behalfOf}}</strong>'
                        +       '{{if behalfOfExists}}</a>{{/if}}'
                        +     ') '
                        +     '{{/if}}'
                        +     '{{te:action}} '
                        +     '{{if url}}<a href="{{:url}}">{{/if}}{{te:target targetReplacements}}{{if url}}</a>{{/if}}'
                        +     '{{if preposition && projectList}} {{te:preposition}} {{:projectList}}{{/if}}'
                        +     '<div class="description force-wrap">{{:description}}</div>'
                        +   '</td>'
                        +   '<td rowspan="2" class="color-stripe"></td>'
                        + '</tr>'
                        + '<tr id="{{>id}}-append" class="row-append">'
                        +   '<td>'
                        +     '{{if condensed}}'
                        +       '<small><span class="timeago muted" title="{{>date}}"></span></small>'
                        +     '{{/if}}'
                        +     '{{if topic}}'
                        +       '<div class="comment-link{{if !comments.count}} no-comments{{/if}}">'
                        +         '<small><i class="icon-comment"></i> '
                        +         '<a href="{{:comments.url}}" title="{{>comments.title}}">{{>comments.label}}</a></small>'
                        +       '</div>'
                        +     '{{/if}}'
                        +   '</td>'
                        + '</tr>'
                        + '<tr class="row-spacing">'
                        +   '<td colspan="3"></td>'
                        + '</tr>'
                    ).render(event);

                    var row = $(html);
                    table.find('tbody.activity-table').append(row);

                    // truncate the description
                    row.find('div.description').expander();

                    // convert times to time-ago
                    row.find('.timeago').formatTimestamp();
                });

                // load again if we get less than half the results we asked for
                // or the results don't fill the page (e.g. due to change filtering)
                deficit = (deficit === undefined ? max : deficit) - data.length;
                if (deficit > Math.round(max / 2) || table.height() < $(window).height()) {
                    swarm.activity._loading = false;
                    return swarm.activity.load(stream, false, deficit);
                }

                // enforce a minimal delay between requests
                setTimeout(function(){ swarm.activity._loading = false; }, 500);
            }
        });
    }
};
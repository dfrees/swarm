/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

$.fn.extend({
    formatTimestamp: function() {
        return this.each(function() {
             $(this).text(swarm.timeFormatter.format(this));
        });
    }
});

swarm.timeFormatter = {
    init: function() {
        // Get the timePreferences from the body.
        var timePreferences = $('body').data('timePreferences');

        if (timePreferences !== 'undefined' && timePreferences.display
            && timePreferences.display.toLowerCase() === 'timestamp') {
            // If not server format we will set it to be local time.
            this.format = function (span) {
                var time = $(span).attr('title');
                var date = new Date(time);
                return date.toLocaleString();
            };
            // Setup the convert to be timestamp.
            this.convert = function (time) {
                var date = new Date(time);
                return date.toLocaleString();
            };
            // Setup the timeValue
            this.timeValue = function (time) {
                return time*1000;
            };
        }
    },
    format: function(span) {
        return $(span).timeago().text();
    },
    convert: function(time) {
        return $.timeago.inWords(time);
    },
    timeValue: function(time) {
       return (Date.now() - (time * 1000));
    }
};

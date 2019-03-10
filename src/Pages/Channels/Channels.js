new Vue({
    el: '#app',
    data: {
        channels: null,
        logicalChannelsNumbers: null
    },
    computed: {
        orderedChannels: function () {
            if (this.channels == null) {
                return null;
            }
            if (this.logicalChannelsNumbers == null) {
                return this.channels;
            }
            var vm = this;
            return this.channels.sort(function(a, b) {
                // Channels without logical number should go to the end
                aNumber = vm.logicalChannelsNumbers[a["SERVICE_ID"]] ? vm.logicalChannelsNumbers[a["SERVICE_ID"]] : 1000;
                bNumber = vm.logicalChannelsNumbers[b["SERVICE_ID"]] ? vm.logicalChannelsNumbers[b["SERVICE_ID"]] : 1000;
                return Math.sign(aNumber - bNumber);
            });
        }
    },
    created() {
        var that = this;
        $.ajax({
            url: '/api/channels/get-all',
            success: function (channels) {
                that.channels = channels;
            },
        });
        $.ajax({
            url: '/api/channels/logical-numbers',
            success: function (logicalChannelsNumbers) {
                that.logicalChannelsNumbers = logicalChannelsNumbers;
            },
        });
    },
});
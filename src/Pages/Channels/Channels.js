new Vue({
    el: '#app',
    data: {
        channels: null,
        logicalChannelsNumbers: null,
        runningEpg: null,
        now: Math.floor(Date.now() / 1000),
        incompleteEpgCount: 0,
        rtspRoot: rtspRoot
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
            return this.channels.sort(function (a, b) {
                // Channels without logical number should go to the end
                aNumber = vm.logicalChannelsNumbers[a["SERVICE_ID"]] ? vm.logicalChannelsNumbers[a["SERVICE_ID"]] : 1000;
                bNumber = vm.logicalChannelsNumbers[b["SERVICE_ID"]] ? vm.logicalChannelsNumbers[b["SERVICE_ID"]] : 1000;
                return Math.sign(aNumber - bNumber);
            });
        },
        /**
         * EIT accessible by service id
         */
        epgByService: function () {
            if (!this.runningEpg) {
                return null;
            }
            var epgByService = {};
            for (var i = 0; i < this.runningEpg.length; i++) {
                epgByService[this.runningEpg[i].serviceId] = this.runningEpg[i];
                epgByService[this.runningEpg[i].serviceId].name = this.getEitName(this.runningEpg[i]);
                epgByService[this.runningEpg[i].serviceId].shortText = this.getEitShortText(this.runningEpg[i]);
            }
            return epgByService;
        },
        missingEpgServices: function () {
            if (!this.channels) {
                return null;
            }
            var channelsServiceIds = this.channels.map(function (channel) {
                return parseInt(channel.SERVICE_ID);
            });
            if (!this.runningEpg) {
                return channelsServiceIds
            }
            var runningEpgServiceIds = this.runningEpg.map(function (eit) {
                return parseInt(eit.serviceId);
            });
            return channelsServiceIds.filter(function (channel) {
                return runningEpgServiceIds.indexOf(channel) < 0;
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
        this.getEpg();
        setInterval(function () {
            that.now = Math.floor(Date.now() / 1000);
        }, 5000);
    },
    methods: {
        getEpg: function () {
            var that = this;
            $.ajax({
                url: '/api/epg/get-running',
                success: function (runningEpg) {
                    that.runningEpg = runningEpg;
                    if (that.missingEpgServices.length > 0) {
                        that.incompleteEpgCount++;
                        if (that.incompleteEpgCount>10) {
                            // If EPG is still incomplete after that time, it will probably never be...
                            return;
                        }
                        setTimeout(function () {
                            that.getEpg();
                        }, 2000);
                    }
                },
            });
        },
        getEitName: function (eit) {
            if (!eit.descriptors || eit.descriptors.length == 0) {
                return '';
            }
            for (var i = 0; i < eit.descriptors.length; i++) {
                if (eit.descriptors[i]._descriptorName != 'PhpBg\\DvbPsi\\Descriptors\\ShortEvent') {
                    continue;
                }
                return eit.descriptors[i].eventName ? eit.descriptors[i].eventName : '';
            }
            return '';
        },
        getEitShortText: function (eit) {
            if (!eit.descriptors || eit.descriptors.length == 0) {
                return '';
            }
            for (var i = 0; i < eit.descriptors.length; i++) {
                if (eit.descriptors[i]._descriptorName != 'PhpBg\\DvbPsi\\Descriptors\\ShortEvent') {
                    continue;
                }
                return eit.descriptors[i].text ? eit.descriptors[i].text : '';
            }
            return '';
        },
        getEitPercent: function (eit) {
            var percent = Math.floor(100 * (this.now - eit.startTimestamp) / (this.now - eit.startTimestamp + eit.duration));
            if (percent >= 100) {
                this.getEpg();
            }
            return percent;
        }
    }
});
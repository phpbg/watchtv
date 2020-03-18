new Vue({
    el: '#app',
    data: {
        channels: null,
        logicalChannelsNumbers: null,
        eitAggregators: null,
        timeRatio: 10,
        hourDisplayStep: 30 * 60,
        mouseOrigin: null,
        scrollOrigin: null,
        firstTimeScoll: false,
        now: Math.floor(Date.now() / 1000)
    },
    updated: function() {
        if (! (this.firstTimeScoll) && this.$refs.epgContainer) {
            const x = Math.round((this.now - this.timeReference)/this.timeRatio) - this.$refs.epgContainer.offsetWidth/2;
            this.$refs.epgContainer.scroll(x, 0)
        }
    },
    computed: {
        showEpg: function() {
            return this.channels !== null && this.eitAggregators !== null
        },
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
        minMaxTimestamp() {
            if (this.eitAggregators == null) return null;
            var now = Math.floor(Date.now() / 1000);
            return this
                .eitAggregators
                .reduce((acc, currentAggregator) => {
                    var minMax = currentAggregator.events.reduce((internalAcc, current) => {
                        if (internalAcc.min > current.startTimestamp) {
                            internalAcc.min = current.startTimestamp;
                        }
                        if (internalAcc.max < current.startTimestamp + current.duration) {
                            internalAcc.max = current.startTimestamp + current.duration;
                        }
                        return internalAcc;
                    }, {min: now, max: now});
                    if (acc.min > minMax.min) {
                        acc.min = minMax.min;
                    }
                    if (acc.max < minMax.max) {
                        acc.max = minMax.max;
                    }
                    return acc;
                }, {min: now, max: now})

        },
        timeReference() {
            if (this.minMaxTimestamp == null) return null;
            return this.minMaxTimestamp.min;
        },
        hours() {
            const hours = [];
            const min = this.minMaxTimestamp.min - (this.minMaxTimestamp.min%this.hourDisplayStep);
            const max = this.minMaxTimestamp.max + (this.minMaxTimestamp.max%this.hourDisplayStep);
            for (let i = min; i < max; i+=this.hourDisplayStep) {
                hours.push(i);
            }
            return hours;
        },
        days() {
            let first = null;
            if (this.hours.length > 0) {
                first = this.hours[0];
            } else {
                first = moment().unix();
            }
            const days = this
                .hours
                .filter((hourTimestamp) => hourTimestamp%(24*3600) === 0);

            days.unshift(first);
            return days;
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
        $.ajax({
            url: '/api/epg/get-all',
            success: function (eitAggregators) {
                that.eitAggregators = eitAggregators;
            },
        });
    },
    methods: {
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
        computeStyle(startTimestamp, duration) {
            const left = Math.round((startTimestamp - this.timeReference)/this.timeRatio);
            const width = Math.round(duration / this.timeRatio);
            return `left: ${left}px; width: ${width}px`;
        },
        computeStyleFromEPGEvent(event) {
            return this.computeStyle(event.startTimestamp, event.duration)
        },
        computeStyleFromHourStep(hourTimestamp) {
            return this.computeStyle(hourTimestamp, this.hourDisplayStep)
        },
        computeStyleFromDayStep(hourTimestamp) {
            let maxTimestamp = null;
            if (this.hours.length > 0) {
                maxTimestamp = this.hours[this.hours.length-1] + this.hourDisplayStep;
            } else {
                maxTimestamp = this.minMaxTimestamp.max;
            }
            const duration = Math.min(24 * 3600, maxTimestamp - hourTimestamp);
            return this.computeStyle(hourTimestamp, duration)
        },
        computeContainerStyle() {
            let height = 30;
            if (this.channels != null) {
                height = height * (2 + this.channels.length);
            }
            return `height: ${height}px`;
        },
        computeNowLineStyle() {
            const left = Math.round((this.now - this.timeReference)/this.timeRatio);
            let height = 30;
            if (this.channels != null) {
                height = height * (2 + this.channels.length);
            }
            return `left: ${left}px;height: ${height}px`;
        },
        dragStart(event) {
            this.mouseOrigin = event.clientX != null ? event.clientX : event.touches[0].clientX;
            this.scrollOrigin = this.$refs.epgContainer.scrollLeft;
        },
        drag(event) {
            if (this.mouseOrigin !== null) {
                const newx = event.clientX != null ? event.clientX : event.touches[0].clientX;
                const dx = newx - this.mouseOrigin;
                this.$refs.epgContainer.scroll(this.scrollOrigin-dx, 0);
            }
        },
        dragStop() {
            this.mouseOrigin = null;
        },
        getEitAggregator(channel) {
            if (this.eitAggregators == null) return [];
            const eitaggregator = this
                .eitAggregators
                .filter((aggregator) => aggregator.serviceId == channel.SERVICE_ID);
            if (eitaggregator.length === 0) return {};
            return eitaggregator[0];
        },
        getEventsForChannel(channel) {
            const eitAggregator = this.getEitAggregator(channel);
            if (eitAggregator == null || eitAggregator.events == null) return [];
            return eitAggregator.events;
        }
    },
    filters: {
        toDay(timestamp) {
            const date = moment.unix(timestamp);
            return date.format('LL');
        },
        toHour(timestamp) {
            const date = moment.unix(timestamp);
            return date.format('LT');
        },
    }
});
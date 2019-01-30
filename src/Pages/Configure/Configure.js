new Vue({
    el: '#app',
    data: {
        checks: [],
        initialScanNetwork: "",
        initialScanFile: "",
        networks: ['atsc', 'dvb-c', 'dvb-s', 'dvb-t', 'isdb-t'],
        initialScanFiles: []
    },
    created() {
        var that = this;
        $.ajax({
            url: '/api/check-configuration',
            success: function (checks) {
                that.checks = checks;
            },
        });
    },
    watch: {
        initialScanNetwork: function(newVal) {
            var that = this;
            that.initialScanFiles = [];
            that.initialScanFile = "";
            $.ajax({
                url: '/api/initial-scan-files',
                data: {network: newVal},
                success: function (initialScanFiles) {
                    for (var property in initialScanFiles) {
                        if (initialScanFiles.hasOwnProperty(property)) {
                            that.initialScanFiles.push({
                                value: property,
                                text: initialScanFiles[property]
                            });
                        }
                    }
                },
            });

        }
    },
    computed: {
        showScan: function () {
            return this.checks.length > 0 && this.checks.reduce(function (accumulator, check) {
                return accumulator && check.status == 0;
            }, true);
        }
    },
    methods: {
        refreshChannels: function() {
            $.ajax({
                url: '/api/channels/reload',
            }).done(function () {
                window.location.href = '/';
            }).fail(function() {
                alert('an error occurred');
            })
        }
    }
});
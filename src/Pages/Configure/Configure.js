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
        scanners: function() {
            return this.checks.filter(function(check) {
                return check.isScanner;
            });
        },
        hasScanner: function () {
            return this.scanners.filter(function(check) {
                return check.works;
            }).length > 0;
        },
        tuners: function() {
            return this.checks.filter(function(check) {
                return check.isTuner;
            });
        },
        hasTuner: function () {
            return this.tuners.filter(function(check) {
                return check.works;
            }).length > 0;
        },
        showScan: function () {
            return this.hasScanner && this.hasTuner;
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
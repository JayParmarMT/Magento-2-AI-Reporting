/**
 * Meetanshi AIReporting — RequireJS Config
 */
var config = {
    paths: {
        'chartjs': 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min'
    },
    shim: {
        'chartjs': {
            exports: 'Chart'
        }
    }
};

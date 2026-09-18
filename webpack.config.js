const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// entry 'main' → js/farmacia-main.js (matches Util::addScript in PageController).
webpackConfig.entry = { main: path.join(__dirname, 'src', 'main.js') }

module.exports = webpackConfig
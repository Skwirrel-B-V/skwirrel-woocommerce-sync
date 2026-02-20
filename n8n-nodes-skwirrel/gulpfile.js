const { src, dest } = require('gulp');

function copyIcons() {
	return src('nodes/**/*.svg').pipe(dest('dist/nodes'));
}

function copyJson() {
	return src('nodes/**/*.json').pipe(dest('dist/nodes'));
}

exports['build:icons'] = copyIcons;
exports['build:json'] = copyJson;
exports.default = async function () {
	await copyIcons();
	await copyJson();
};

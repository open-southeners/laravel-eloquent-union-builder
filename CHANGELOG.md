# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.1] - 2022-10-27

### Fixed

- Method `UnionBuilder::callingOnly` is now null-safe

## [0.3.0] - 2022-10-27

### Added

- Third optional parameter to `UnionBuilder::search` which lets modify Laravel Scout builder instance used for search

## [0.2.0] - 2022-10-27

### Changed

- `UnionBuilder::callOnly` now is `UnionBuilder::callingOnly` passing a callback function as second parameter

### Fixed

- Use `forceFill` to model data mapping as some models doesn't get all attributes

## [0.1.0] - 2022-10-26

### Added

- Initial release!

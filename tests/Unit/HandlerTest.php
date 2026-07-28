<?php

use Handler\LogHandler;
use Handler\AnalyseHandler;
use Handler\RawHandler;
use Handler\LimitsHandler;
use Handler\FiltersHandler;
use Handler\RateErrorHandler;
use Handler\InsightsHandler;

test('LogHandler responds to POST /1/log', function () {
    // Handler instantiation test
    $handler = new LogHandler();
    expect($handler)->toBeInstanceOf(LogHandler::class);
});

test('AnalyseHandler responds to POST /1/analyse', function () {
    $handler = new AnalyseHandler();
    expect($handler)->toBeInstanceOf(AnalyseHandler::class);
});

test('RawHandler responds to GET /1/raw/{id}', function () {
    $handler = new RawHandler();
    expect($handler)->toBeInstanceOf(RawHandler::class);
});

test('LimitsHandler responds to GET /1/limits', function () {
    $handler = new LimitsHandler();
    expect($handler)->toBeInstanceOf(LimitsHandler::class);
});

test('FiltersHandler responds to GET /1/filters', function () {
    $handler = new FiltersHandler();
    expect($handler)->toBeInstanceOf(FiltersHandler::class);
});

test('RateErrorHandler responds to GET /1/errors/rate', function () {
    $handler = new RateErrorHandler();
    expect($handler)->toBeInstanceOf(RateErrorHandler::class);
});

test('InsightsHandler responds to GET /1/insights/{id}', function () {
    $handler = new InsightsHandler();
    expect($handler)->toBeInstanceOf(InsightsHandler::class);
});
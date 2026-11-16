<?php

declare(strict_types=1);

// DB::credentials(): In Produktion ist die eingeschränkte Rolle Pflicht (RLS greift nur für sie).

test('DB-Zugang Produktion: ohne APP_DB_USER Abbruch mit klarer Meldung', function () {
    foreach ([['APP_ENV' => 'production'], ['APP_ENV' => ''], []] as $env) {
        try {
            DB::credentials($env + ['DB_USER' => 'eeg', 'DB_PASSWORD' => 'x']);
            throw new Exception('hätte abbrechen müssen: ' . json_encode($env));
        } catch (RuntimeException $e) {
            assertContains('APP_DB_USER', $e->getMessage());
        }
    }
});

test('DB-Zugang Produktion: APP_DB_PASSWORD fehlt -> Abbruch', function () {
    try {
        DB::credentials(['APP_ENV' => 'production', 'APP_DB_USER' => 'eeg_app', 'DB_USER' => 'eeg']);
        throw new Exception('hätte abbrechen müssen');
    } catch (RuntimeException $e) {
        assertContains('APP_DB_PASSWORD', $e->getMessage());
    }
});

test('DB-Zugang Produktion: APP_DB_USER gleich DB_USER -> Abbruch', function () {
    try {
        DB::credentials(['APP_ENV' => 'production', 'APP_DB_USER' => 'eeg', 'APP_DB_PASSWORD' => 'x', 'DB_USER' => 'eeg']);
        throw new Exception('hätte abbrechen müssen');
    } catch (RuntimeException $e) {
        assertContains('nicht dieselbe Rolle', $e->getMessage());
    }
});

test('DB-Zugang Produktion: eingeschränkte Rolle wird verwendet', function () {
    assertSame(['eeg_app', 'pw'], DB::credentials([
        'APP_ENV' => 'production', 'APP_DB_USER' => 'eeg_app', 'APP_DB_PASSWORD' => 'pw',
        'DB_USER' => 'eeg', 'DB_PASSWORD' => 'owner',
    ]));
});

test('DB-Zugang Entwicklung: Rückfall auf DB_USER erlaubt, APP_DB_USER bevorzugt', function () {
    assertSame(['eeg', 'owner'], DB::credentials(['APP_ENV' => 'development', 'DB_USER' => 'eeg', 'DB_PASSWORD' => 'owner']));
    assertSame(['eeg_app', 'pw'], DB::credentials([
        'APP_ENV' => 'Development', 'APP_DB_USER' => 'eeg_app', 'APP_DB_PASSWORD' => 'pw', 'DB_USER' => 'eeg',
    ]));
});

test('DB::isProduction: nur ausdrückliche Entwicklungs-/Testwerte schalten ab', function () {
    assertTrue(DB::isProduction('production'));
    assertTrue(DB::isProduction(''));
    assertTrue(DB::isProduction('prod'));
    assertFalse(DB::isProduction('development'));
    assertFalse(DB::isProduction(' TEST '));
});

<?php

declare(strict_types=1);

// Kurznamen in der Rollenwahl (functions.php: communityShortName(), roleSwitcherLabel()).

test('communityShortName kürzt wie gewünscht', function () {
    assertSame('EEG Strompool FE SW', communityShortName('EEG Strompool Feldkirchen Süd-West'));
    assertSame('EEG Strompool FE SW', communityShortName('Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West (Verein)'));
    assertSame('EEG Strompool FE SW', communityShortName('Erneuerbare Energiegemeinschaft Strompool Feldkirchen Süd-West'));
});

test('communityShortName lässt kurze Namen unverändert', function () {
    assertSame('EEG Sonnenhof', communityShortName('EEG Sonnenhof'));
    assertSame('EEG Ödland', communityShortName('Erneuerbare Energiegemeinschaft Ödland'));
});

test('communityShortName nutzt Großbuchstaben auch bei Umlauten', function () {
    assertSame('EEG Sonnenstrom ÖT BE', communityShortName('EEG Sonnenstrom Ötztal am Berg'));
});

test('roleSwitcherLabel: Admin, EEG-Kurzname, Vor- und Nachname', function () {
    $roles = [
        ['role' => 'platform_admin', 'community_id' => null],
        ['role' => 'manager', 'community_id' => 'c1', 'community_name' => 'EEG Strompool Feldkirchen Süd-West'],
        ['role' => 'member', 'community_id' => 'c1', 'community_name' => 'EEG Strompool Feldkirchen Süd-West', 'member_first_name' => 'Patrick', 'member_last_name' => 'Ropper'],
    ];
    assertSame('Admin', roleSwitcherLabel($roles[0], $roles));
    assertSame('EEG Strompool FE SW', roleSwitcherLabel($roles[1], $roles));
    assertSame('Patrick Ropper', roleSwitcherLabel($roles[2], $roles));
});

test('roleSwitcherLabel: gleicher Name in zwei EEGs bekommt den EEG-Kurznamen dazu', function () {
    $roles = [
        ['role' => 'member', 'community_id' => 'c1', 'community_name' => 'EEG Strompool Feldkirchen Süd-West', 'member_first_name' => 'Anna', 'member_last_name' => 'Muster'],
        ['role' => 'member', 'community_id' => 'c2', 'community_name' => 'EEG Sonnenhof', 'member_first_name' => 'Anna', 'member_last_name' => 'Muster'],
    ];
    assertSame('Anna Muster (EEG Strompool FE SW)', roleSwitcherLabel($roles[0], $roles));
    assertSame('Anna Muster (EEG Sonnenhof)', roleSwitcherLabel($roles[1], $roles));
});

<?php

declare(strict_types=1);

// SEPA-Lastschrift pain.008 (sepaPain008Xml).

$creditor = ['name' => 'EEG Test', 'iban' => 'AT61 1904 3002 3457 3201', 'bic' => 'BKAUATWW', 'creditor_id' => 'AT12ZZZ00000000001'];
$txns = [
    ['end_to_end_id' => 'E2E1', 'amount' => 12.34, 'mandate_ref' => 'S00000F2026A100', 'mandate_date' => '2026-01-15',
     'debtor_name' => 'Max Muster', 'debtor_iban' => 'AT02 2011 1000 0000 1234', 'debtor_bic' => 'GIBAATWW', 'remittance' => 'Rechnung 2026-Q3'],
    ['end_to_end_id' => 'E2E2', 'amount' => 7.66, 'mandate_ref' => 'S00000F2026A101', 'mandate_date' => '2026-02-01',
     'debtor_name' => 'Eva Test', 'debtor_iban' => 'AT023200000000005678', 'debtor_bic' => '', 'remittance' => 'Rechnung 2026-Q3'],
];

test('pain.008.08: Namespace, BICFI, Summe, Mandat, Gläubiger-ID', function () use ($creditor, $txns) {
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG1');
    assertContains('pain.008.001.08', $xml);
    assertContains('<BICFI>BKAUATWW</BICFI>', $xml);
    assertContains('<CtrlSum>20.00</CtrlSum>', $xml);
    assertContains('<NbOfTxs>2</NbOfTxs>', $xml);
    assertContains('<MndtId>S00000F2026A100</MndtId>', $xml);
    assertContains('<Id>AT12ZZZ00000000001</Id>', $xml);
});

test('pain.008.02: alte Version nutzt <BIC> statt <BICFI>', function () use ($creditor, $txns) {
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '02', 'RCUR', 'MSG2');
    assertContains('pain.008.001.02', $xml);
    assertContains('<BIC>BKAUATWW</BIC>', $xml);
    assertFalse(str_contains($xml, 'BICFI'));
});

test('Fehlende Debtor-BIC -> NOTPROVIDED', function () use ($creditor, $txns) {
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG3');
    assertContains('<Othr><Id>NOTPROVIDED</Id></Othr>', $xml);
});

test('IBAN-Leerzeichen werden entfernt', function () use ($creditor, $txns) {
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG4');
    assertContains('<IBAN>AT022011100000001234</IBAN>', $xml);
});

test('Ergebnis ist wohlgeformtes XML', function () use ($creditor, $txns) {
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG5');
    $prev = libxml_use_internal_errors(true);
    $ok = simplexml_load_string($xml) !== false;
    libxml_use_internal_errors($prev);
    assertTrue($ok, 'XML sollte parsebar sein');
});

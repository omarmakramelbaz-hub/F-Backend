<style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { background: #edf0f3; color: #111; font: 12px/1.35 Tahoma, Arial, sans-serif; }
    .receipt { width: 80mm; max-width: calc(100% - 16px); margin: 12px auto; padding: 8px 12px 6px; background: #fff; color: #111; font: 12px/1.35 Tahoma, Arial, sans-serif; box-shadow: 0 4px 22px #00000013; overflow-wrap: anywhere; color-scheme: light; }
    .receipt, .receipt * { color: #111; -webkit-text-fill-color: #111; text-shadow: none; }
    .receipt p, .receipt dl { margin: 0; }
    .receipt bdi { direction: ltr; unicode-bidi: isolate; font-family: Arial, sans-serif; font-variant-numeric: tabular-nums; }
    .ft-brand { text-align: center; padding-bottom: 3px; }
    .ft-logo { display: block; width: 80px; height: 80px; max-width: 100%; margin: 0 auto; object-fit: contain; filter: grayscale(1) contrast(1.2); }
    .receipt .ft-branch { margin: 1px 0 0; font-size: 11px; line-height: 1.35; }
    .ft-contact-line { font-size: 11px; line-height: 1.3; overflow-wrap: anywhere; }
    .ft-identity { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 2px 6px; border-block: 1px solid #111; padding: 3px 0; }
    .ft-type { font-size: 12px; font-weight: bold; }
    .ft-number, .ft-number-long { display: block; min-width: 0; max-width: 100%; font-size: 14px; line-height: 1.3; letter-spacing: 0; font-weight: bold; text-align: end; }
    .ft-number-long { font-size: 12px; }
    .ft-date { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1px 6px; font-size: 11px; padding: 3px 0 2px; }
    .ft-state { border: 1px solid #111; padding: 2px 4px; margin-top: 3px !important; font-size: 11px; font-weight: bold; text-align: center; }
    .receipt .ft-meta { border-top: 1px dashed #888; margin: 0; padding: 2px 0 3px; }
    .ft-meta > div { display: table; table-layout: fixed; width: 100%; margin: 1px 0; break-inside: avoid; page-break-inside: avoid; }
    .ft-meta dt, .ft-meta dd { display: table-cell; vertical-align: top; margin: 0; min-width: 0; overflow-wrap: anywhere; word-wrap: break-word; line-height: 1.35; }
    .ft-meta dt { width: 20%; padding-inline-end: 4px; font-size: 11px; font-weight: normal; }
    .ft-meta dd { width: 80%; font-size: 12px; font-weight: bold; }
    .ft-items { width: 100%; max-width: 100%; table-layout: fixed; border-collapse: collapse; border-spacing: 0; margin: 0; background: transparent; }
    .ft-name-col { width: 57%; }.ft-qty-col { width: 19%; }.ft-price-col { width: 10%; }.ft-amount-col { width: 14%; }
    .ft-items th, .ft-items td { color: #111; background: transparent; font-size: 11px; line-height: 1.3; text-align: start; vertical-align: middle; }
    .ft-items th { border-block: 1px solid #111; padding: 3px 0; font-weight: normal; white-space: nowrap; }
    .ft-items td { border-bottom: 1px dotted #aaa; padding: 3px 0; }
    .ft-items td:first-child { padding-inline-end: 1px; }
    .ft-items th:not(:first-child), .ft-items td:not(:first-child) { padding-inline-start: 1px; }
    .ft-item-label, .ft-cell-value { display: block; width: 100%; white-space: normal; overflow-wrap: anywhere; }
    .ft-item-label { font-size: 11.5px; line-height: 1.3; }
    .ft-item-name { font-size: inherit; font-weight: bold; }
    .ft-option, .ft-unit { display: inline; font-size: inherit; line-height: inherit; font-weight: normal; }
    .ft-cell-value { font-size: 11px; }
    .ft-items .ft-qty { text-align: center; }
    .ft-items .ft-price, .ft-items .ft-amount { text-align: end; }
    .ft-amount { font-weight: bold; }
    .ft-items thead { display: table-header-group; }
    .ft-items tr { break-inside: avoid; page-break-inside: avoid; }
    .ft-no-price .ft-name-col { width: 64%; }.ft-no-price .ft-qty-col { width: 20%; }.ft-no-price .ft-amount-col { width: 16%; }
    .ft-summary { padding-top: 3px; break-inside: avoid; page-break-inside: avoid; }
    .ft-sum { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; font-size: 12px; padding: 1px 0; }
    .ft-sum > * { min-width: 0; overflow-wrap: anywhere; }
    .ft-value { text-align: end; font-weight: bold; }
    .ft-grand { margin-top: 3px; padding: 4px 0; border-top: 1.5px solid #111; border-bottom: 1px solid #111; font-size: 15px; font-weight: bold; }
    .ft-grand bdi { font-size: 18px; line-height: 1.2; }
    .ft-currency { font-size: 11px; font-weight: normal; }
    .ft-pay { padding: 3px 0 1px; font-size: 11px; }
    .ft-tender { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 8px; padding: 2px 0; font-size: 12px; }
    .ft-tender bdi { margin-inline-start: 4px; font-weight: bold; }
    .ft-note { font-size: 11px; line-height: 1.35; white-space: pre-line; overflow-wrap: anywhere; margin-top: 3px !important; padding-top: 3px; border-top: 1px dashed #999; break-inside: avoid; page-break-inside: avoid; }
    .ft-footer { font-size: 11px; line-height: 1.3; text-align: center; margin-top: 4px; padding-top: 3px; border-top: 1px solid #111; break-inside: avoid; page-break-inside: avoid; }
    .ft-kitchen .ft-name-col { width: 76%; }.ft-kitchen .ft-qty-col { width: 24%; }
    .ft-kitchen .ft-items td { padding: 4px 0; }
    .ft-kitchen .ft-item-label, .ft-kitchen .ft-cell-value { font-size: 13px; }
    .ft-kitchen .ft-items .ft-qty { font-weight: bold; text-align: end; }
    .ft-kitchen .ft-note { border: 1px dashed #777; padding: 3px 4px; margin-top: 4px !important; }
    .controls { margin: 12px auto; text-align: center; font: 13px Tahoma, Arial, sans-serif; }
    .controls button { padding: 9px 16px; border: 0; border-radius: 6px; background: #142d43; color: #fff; font: inherit; cursor: pointer; }
    .preview-title { text-align: center; font: 13px Tahoma, Arial, sans-serif; margin: 12px; }
    @page { size: auto; margin: 0; }
    @media print {
        html, body { width: 100%; background: #fff; color: #000; }
        .receipt { width: calc(100% - 8mm); max-width: 72mm; margin: 0 auto; padding: 1.2mm 0; box-shadow: none; border: 0; border-radius: 0; }
        .ft-brand, .ft-identity { break-inside: avoid; page-break-inside: avoid; }
        .controls, .preview-title { display: none !important; }
    }
    @media print and (max-width: 60mm) { .receipt { width: calc(100% - 10mm); } }
</style>

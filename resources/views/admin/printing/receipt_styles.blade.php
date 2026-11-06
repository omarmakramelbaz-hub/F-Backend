<style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { background: #edf0f3; color: #111; font: 12px/1.65 Tahoma, Arial, sans-serif; }
    .receipt { width: 58mm; max-width: calc(100% - 24px); margin: 16px auto; padding: 13px 11px 12px; background: #fff; color: #111; font: 12px/1.65 Tahoma, Arial, sans-serif; box-shadow: 0 4px 22px #00000013; overflow-wrap: anywhere; color-scheme: light; }
    .receipt, .receipt * { color: #111; -webkit-text-fill-color: #111; text-shadow: none; }
    .receipt p, .receipt dl { margin: 0; }
    .receipt bdi { direction: ltr; unicode-bidi: isolate; font-family: Arial, sans-serif; font-variant-numeric: tabular-nums; white-space: normal; overflow-wrap: anywhere; }
    .ft-brand { text-align: center; padding-bottom: 6px; }
    .ft-logo { display: block; width: 80px; height: 80px; max-width: 100%; margin: 0 auto; object-fit: contain; filter: grayscale(1) contrast(1.2); }
    .receipt .ft-branch { margin: 2px 0 0; font-size: 11px; line-height: 1.65; }
    .ft-contact-line { font-size: 11px; overflow-wrap: anywhere; }
    .ft-identity { display: flex; justify-content: space-between; align-items: center; gap: 8px; border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 5px 0; }
    .ft-identity > * { min-width: 0; max-width: 100%; }
    .ft-type { font-size: 12px; font-weight: bold; }
    .ft-kind { font-size: 11px; }
    .ft-number { display: block; max-width: 64%; font-size: 22px; line-height: 1.3; letter-spacing: .5px; font-weight: bold; text-align: end; }
    .ft-number-long { font-size: 13px; letter-spacing: 0; }
    .ft-date { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 6px; font-size: 11px; padding: 5px 0 4px; }
    .ft-state { border: 1px solid #111; padding: 3px 5px; margin-top: 5px !important; font-size: 11px; font-weight: bold; text-align: center; }
    .receipt .ft-meta { border-top: 1px dashed #888; margin: 0; padding: 5px 0 6px; }
    .ft-meta > div { display: table; table-layout: fixed; width: 100%; margin: 3px 0; break-inside: avoid; page-break-inside: avoid; }
    .ft-meta dt, .ft-meta dd { display: table-cell; vertical-align: top; margin: 0; min-width: 0; overflow-wrap: anywhere; word-wrap: break-word; }
    .ft-meta dt { width: 29%; padding-inline-end: 5px; font-size: 11px; font-weight: normal; }
    .ft-meta dd { width: 71%; font-size: 12px; font-weight: bold; }
    .ft-items { width: 100%; max-width: 100%; table-layout: fixed; border-collapse: collapse; border-spacing: 0; margin: 0; background: transparent; }
    .ft-name-col { width: 36%; }.ft-qty-col { width: 20%; }.ft-price-col, .ft-amount-col { width: 22%; }
    .ft-items th, .ft-items td { color: #111; background: transparent; white-space: normal; overflow: visible; overflow-wrap: anywhere; word-wrap: break-word; font-size: 11px; line-height: 1.7; text-align: start; vertical-align: top; }
    .ft-items th { border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 4px 0; font-weight: normal; }
    .ft-items td { border-bottom: 1px dashed #aaa; padding: 6px 0; }
    .ft-items th:not(:first-child), .ft-items td:not(:first-child) { padding-inline-start: 1px; }
    .ft-items td:first-child { padding-inline-end: 3px; }
    .ft-item-name { font-size: 12px; line-height: 1.65; font-weight: bold; }
    .ft-option, .ft-unit { display: block; font-size: 11px; line-height: 1.65; font-weight: normal; }
    .ft-option { margin-top: 1px; }
    .ft-items .ft-qty { text-align: center; }
    .ft-items .ft-price, .ft-items .ft-amount { text-align: end; }
    .ft-amount { font-weight: bold; }
    .ft-items thead { display: table-header-group; }
    .ft-items tr { break-inside: avoid; page-break-inside: avoid; }
    .ft-no-price .ft-name-col { width: 54%; }.ft-no-price .ft-qty-col { width: 20%; }.ft-no-price .ft-amount-col { width: 26%; }
    .ft-summary { padding-top: 6px; break-inside: avoid; page-break-inside: avoid; }
    .ft-sum { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 11px; padding: 2px 0; }
    .ft-sum > * { min-width: 0; overflow-wrap: anywhere; }
    .ft-value { text-align: end; font-weight: bold; }
    .ft-grand { margin-top: 4px; padding: 5px 0; border-top: 1.5px solid #111; border-bottom: 1px solid #111; font-size: 14px; font-weight: bold; }
    .ft-grand bdi { font-size: 20px; line-height: 1.3; }
    .ft-currency { font-size: 11px; font-weight: normal; }
    .ft-pay { padding: 5px 0 2px; font-size: 11px; }
    .ft-note { font-size: 11px; line-height: 1.65; white-space: pre-line; overflow-wrap: anywhere; margin-top: 6px !important; padding-top: 5px; border-top: 1px dashed #999; break-inside: avoid; page-break-inside: avoid; }
    .ft-note strong { font-weight: bold; }
    .ft-footer { font-size: 11px; line-height: 1.65; text-align: center; margin-top: 7px; padding-top: 5px; border-top: 1px solid #111; break-inside: avoid; page-break-inside: avoid; }
    .ft-kitchen .ft-name-col { width: 73%; }.ft-kitchen .ft-qty-col { width: 27%; }
    .ft-kitchen .ft-items td { padding: 7px 0; }
    .ft-kitchen .ft-items .ft-qty { font-size: 13px; font-weight: bold; text-align: end; }
    .ft-kitchen .ft-note { border: 1px dashed #777; padding: 5px 6px; margin-top: 8px !important; }
    .controls { margin: 12px auto; text-align: center; font: 13px Tahoma, Arial, sans-serif; }
    .controls button { padding: 9px 16px; border: 0; border-radius: 6px; background: #142d43; color: #fff; font: inherit; cursor: pointer; }
    .preview-title { text-align: center; font: 13px Tahoma, Arial, sans-serif; margin: 12px; }
    @page { size: auto; margin: 0; }
    @media print {
        html, body { width: 100%; background: #fff; color: #000; }
        .receipt { width: 58mm; max-width: calc(100% - 8mm); margin: 0 auto; padding: 3mm 2.9mm; box-shadow: none; border: 0; border-radius: 0; }
        .ft-brand, .ft-identity { break-inside: avoid; page-break-inside: avoid; }
        .controls, .preview-title { display: none !important; }
    }
</style>

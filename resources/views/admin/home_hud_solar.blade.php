{{-- The Solar System from the GO login logo (admin/partials/go_erp_logo: same planets, colours, periods and start angles),
     circling the GO mark (300 units wide), which sits inside a live solar halo (canvas.hud-sun, drawn by home-hud.js).
     Each planet is drawn twice: its far half behind the halo, its near half in front of it, so planets pass behind the sun and in front of it. --}}
<svg class="hud-solar hud-solar-far" viewBox="-690 -290 1380 580" aria-hidden="true" focusable="false">
    <defs>
        <radialGradient id="go-hud-planet-shade" cx=".28" cy=".25" r=".85">
      <stop offset="0" stop-color="#ffffff" stop-opacity=".13"/><stop offset=".45" stop-color="#000000" stop-opacity="0"/><stop offset=".78" stop-color="#000000" stop-opacity=".18"/><stop offset="1" stop-color="#000000" stop-opacity=".65"/>
    </radialGradient>
        <radialGradient id="go-hud-mercury" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#d7cdc0"/><stop offset=".6" stop-color="#9a9187"/><stop offset="1" stop-color="#4a4541"/>
    </radialGradient><clipPath id="go-hud-mercury-clip" clipPathUnits="userSpaceOnUse"><circle r="7"/></clipPath>
        <radialGradient id="go-hud-venus" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#fff0c6"/><stop offset=".6" stop-color="#d5b57e"/><stop offset="1" stop-color="#77512e"/>
    </radialGradient><clipPath id="go-hud-venus-clip" clipPathUnits="userSpaceOnUse"><circle r="11.5"/></clipPath>
        <radialGradient id="go-hud-earth" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#80d4f4"/><stop offset=".6" stop-color="#2079bc"/><stop offset="1" stop-color="#072742"/>
    </radialGradient><clipPath id="go-hud-earth-clip" clipPathUnits="userSpaceOnUse"><circle r="12.5"/></clipPath>
        <radialGradient id="go-hud-mars" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#f3aa76"/><stop offset=".6" stop-color="#b75432"/><stop offset="1" stop-color="#472218"/>
    </radialGradient><clipPath id="go-hud-mars-clip" clipPathUnits="userSpaceOnUse"><circle r="9"/></clipPath>
        <radialGradient id="go-hud-jupiter" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#f3dfbe"/><stop offset=".6" stop-color="#bd9368"/><stop offset="1" stop-color="#4f3e32"/>
    </radialGradient><clipPath id="go-hud-jupiter-clip" clipPathUnits="userSpaceOnUse"><circle r="28"/></clipPath>
        <radialGradient id="go-hud-saturn" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#fae9b7"/><stop offset=".6" stop-color="#cbb47d"/><stop offset="1" stop-color="#66543a"/>
    </radialGradient><clipPath id="go-hud-saturn-clip" clipPathUnits="userSpaceOnUse"><circle r="23"/></clipPath>
        <radialGradient id="go-hud-uranus" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#ccf9f3"/><stop offset=".6" stop-color="#74bdc5"/><stop offset="1" stop-color="#245b6b"/>
    </radialGradient><clipPath id="go-hud-uranus-clip" clipPathUnits="userSpaceOnUse"><circle r="17"/></clipPath>
        <radialGradient id="go-hud-neptune" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#83b7f5"/><stop offset=".6" stop-color="#3568bb"/><stop offset="1" stop-color="#11284f"/>
    </radialGradient><clipPath id="go-hud-neptune-clip" clipPathUnits="userSpaceOnUse"><circle r="17"/></clipPath>
        <clipPath id="go-hud-far" clipPathUnits="userSpaceOnUse"><rect x="-720" y="-320" width="1440" height="320"/></clipPath>
        <clipPath id="go-hud-near" clipPathUnits="userSpaceOnUse"><rect x="-720" y="0" width="1440" height="320"/></clipPath>
    </defs>
    <g fill="none" stroke="#456078" stroke-width="1.5" stroke-opacity=".42">
        <ellipse rx="286" ry="114.4"/>
        <ellipse rx="330" ry="132.0"/>
        <ellipse rx="378" ry="151.2"/>
        <ellipse rx="424" ry="169.6"/>
        <ellipse rx="510" ry="204.0"/>
        <ellipse rx="566" ry="226.4"/>
        <ellipse rx="616" ry="246.4"/>
        <ellipse rx="660" ry="264.0"/>
    </g>
    <g fill="#b8a18b" opacity=".35"><circle cx="42.8" cy="-191.7" r="0.9"/><circle cx="-322.5" cy="-137.2" r="1.5"/><circle cx="-412.6" cy="-78.0" r="1.3"/><circle cx="408.1" cy="-80.5" r="1.0"/><circle cx="-118.8" cy="-183.6" r="1.2"/><circle cx="-432.8" cy="-52.6" r="1.5"/><circle cx="-284.1" cy="-145.9" r="1.8"/><circle cx="316.2" cy="-138.9" r="1.7"/><circle cx="227.9" cy="-161.2" r="1.5"/><circle cx="398.6" cy="-104.1" r="1.5"/><circle cx="-339.8" cy="-129.0" r="1.7"/><circle cx="363.1" cy="-117.4" r="1.0"/><circle cx="401.8" cy="-98.9" r="1.6"/><circle cx="-337.7" cy="-124.8" r="1.1"/><circle cx="-143.6" cy="-177.2" r="1.1"/><circle cx="134.3" cy="-184.2" r="0.9"/><circle cx="-409.6" cy="-82.4" r="1.6"/><circle cx="204.8" cy="-162.4" r="1.5"/><circle cx="380.7" cy="-116.8" r="1.5"/><circle cx="313.8" cy="-140.3" r="1.3"/><circle cx="439.2" cy="-74.7" r="1.7"/><circle cx="-20.7" cy="-183.2" r="1.8"/><circle cx="405.5" cy="-82.0" r="1.2"/><circle cx="459.7" cy="-56.5" r="1.3"/><circle cx="-170.6" cy="-170.1" r="1.5"/><circle cx="56.4" cy="-183.9" r="1.6"/><circle cx="347.8" cy="-126.5" r="1.9"/><circle cx="-50.7" cy="-180.3" r="1.7"/><circle cx="317.0" cy="-142.9" r="1.7"/><circle cx="431.4" cy="-57.4" r="1.5"/><circle cx="39.8" cy="-186.2" r="1.2"/><circle cx="-85.7" cy="-189.0" r="1.0"/><circle cx="-29.4" cy="-192.4" r="1.2"/><circle cx="-67.0" cy="-180.6" r="1.1"/><circle cx="-410.5" cy="-79.2" r="1.7"/><circle cx="60.0" cy="-182.6" r="1.1"/><circle cx="444.7" cy="-55.8" r="0.9"/></g>
    <g clip-path="url(#go-hud-far)">
        <g data-solar-planet="mercury" style="--orbit-start: -35deg; --orbit-end: 325deg; --counter-start: 35deg; --counter-end: -325deg; --orbit-period: 24s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(-35)">
          <g transform="translate(286 0)">
            <g class="hud-counter" transform="rotate(35)">
              <g transform="scale(1 2.5)">
                <circle r="7" fill="url(#go-hud-mercury)"/>
                <g clip-path="url(#go-hud-mercury-clip)"><g fill="#494541" opacity=".37"><circle cx="-2.8" cy="-2.4" r="1.6"/><circle cx="2.8" cy="1.8" r="1.2"/><circle cx="-1" cy="4" r=".7"/></g><path d="M-4 -1 Q-2 -3 0 -2" fill="none" stroke="#f1dfc8" stroke-width=".5" opacity=".45"/></g>
                <circle r="7" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="venus" style="--orbit-start: 132deg; --orbit-end: 492deg; --counter-start: -132deg; --counter-end: -492deg; --orbit-period: 34s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(132)">
          <g transform="translate(330 0)">
            <g class="hud-counter" transform="rotate(-132)">
              <g transform="scale(1 2.5)">
                <circle r="11.5" fill="url(#go-hud-venus)"/>
                <g clip-path="url(#go-hud-venus-clip)"><path d="M-14 -4 Q-3 -9 13 -3 M-13 2 Q0 -3 13 3 M-10 8 Q0 3 12 8" fill="none" stroke="#f9e4b8" stroke-width="2" opacity=".5"/></g>
                <circle r="11.5" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="earth" style="--orbit-start: 45deg; --orbit-end: 405deg; --counter-start: -45deg; --counter-end: -405deg; --orbit-period: 46s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(45)">
          <g transform="translate(378 0)">
            <g class="hud-counter" transform="rotate(-45)">
              <g transform="scale(1 2.5)">
                <circle r="12.5" fill="url(#go-hud-earth)"/>
                <g clip-path="url(#go-hud-earth-clip)"><path d="M-10 -7 L-5 -10 -1 -7 -2 -3 2 -1 0 4 -3 7 -4 3 -7 1 -5 -3 -10 -4 Z M4 -9 L9 -7 12 -1 7 0 4 -3 2 -4 Z M6 6 L11 4 12 8 7 10 5 8 Z" fill="#74ad68"/><path d="M-10 -2 Q-2 -8 7 -5 M-5 9 Q1 4 11 4" fill="none" stroke="#e8f7ff" stroke-width="1.3" opacity=".65"/><path d="M-6 -11 Q0 -14 6 -10 L1 -9 Z" fill="#e3f5ff"/></g>
                <circle r="12.5" fill="url(#go-hud-planet-shade)"/>
                <circle cx="21" cy="-5" r="2.8" fill="#bfc3c9"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="mars" style="--orbit-start: 220deg; --orbit-end: 580deg; --counter-start: -220deg; --counter-end: -580deg; --orbit-period: 58s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(220)">
          <g transform="translate(424 0)">
            <g class="hud-counter" transform="rotate(-220)">
              <g transform="scale(1 2.5)">
                <circle r="9" fill="url(#go-hud-mars)"/>
                <g clip-path="url(#go-hud-mars-clip)"><path d="M-7 -2 Q-1 -6 3 -2 L6 1 2 4 -2 2 -5 4 Z" fill="#703b2b" opacity=".55"/><ellipse cy="-8" rx="3.5" ry="1.5" fill="#e9dcd0" opacity=".8"/></g>
                <circle r="9" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="jupiter" style="--orbit-start: -65deg; --orbit-end: 295deg; --counter-start: 65deg; --counter-end: -295deg; --orbit-period: 76s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(-65)">
          <g transform="translate(510 0)">
            <g class="hud-counter" transform="rotate(65)">
              <g transform="scale(1 2.5)">
                <circle r="28" fill="url(#go-hud-jupiter)"/>
                <g clip-path="url(#go-hud-jupiter-clip)"><path d="M-34 -17 Q-2 -12 34 -18 L34 -12 Q0 -7 -34 -11 Z" fill="#b67d54"/><path d="M-34 -6 Q-4 -1 34 -7 L34 -1 Q0 3 -34 0 Z" fill="#f3d5a9"/><path d="M-33 4 Q0 9 33 3 L33 10 Q0 15 -33 10 Z" fill="#a96643"/><path d="M-31 15 Q0 20 31 15 L31 20 Q0 26 -31 21 Z" fill="#e4c18c"/><path d="M-30 -23 Q0 -19 30 -24 M-30 1 Q0 6 30 0" fill="none" stroke="#7e523b" stroke-width="1.2" opacity=".45"/><ellipse cx="11" cy="8" rx="6" ry="3.3" fill="#b7684f"/><ellipse cx="10" cy="7.5" rx="3.9" ry="2" fill="#d38c68"/></g>
                <circle r="28" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="saturn" style="--orbit-start: 150deg; --orbit-end: 510deg; --counter-start: -150deg; --counter-end: -510deg; --orbit-period: 98s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(150)">
          <g transform="translate(566 0)">
            <g class="hud-counter" transform="rotate(-150)">
              <g transform="scale(1 2.5)">
                <g transform="rotate(-22)" fill="none"><ellipse rx="46" ry="14" stroke="#bda87c" stroke-width="7" opacity=".75"/><ellipse rx="39" ry="11.8" stroke="#e3d4a7" stroke-width="2.6" opacity=".85"/></g>
                <circle r="23" fill="url(#go-hud-saturn)"/>
                <g clip-path="url(#go-hud-saturn-clip)"><path d="M-27 -11 Q0 -7 27 -12 M-27 -3 Q0 1 27 -4 M-27 5 Q0 9 27 4 M-26 13 Q0 17 26 12" fill="none" stroke="#a58c5b" stroke-width="2.2" opacity=".35"/></g>
                <circle r="23" fill="url(#go-hud-planet-shade)"/>
                <g transform="rotate(-22)" fill="none"><path d="M-46 0 A46 14 0 0 0 46 0" stroke="#d8c698" stroke-width="6"/><path d="M-39 0 A39 11.8 0 0 0 39 0" stroke="#f0dfb2" stroke-width="1.7"/></g>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="uranus" style="--orbit-start: 25deg; --orbit-end: 385deg; --counter-start: -25deg; --counter-end: -385deg; --orbit-period: 122s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(25)">
          <g transform="translate(616 0)">
            <g class="hud-counter" transform="rotate(-25)">
              <g transform="scale(1 2.5)">
                <circle r="17" fill="url(#go-hud-uranus)"/>
                <g clip-path="url(#go-hud-uranus-clip)"><path d="M-19 -5 Q0 -1 19 -5 M-19 4 Q0 8 19 4" fill="none" stroke="#d4f4e8" stroke-width="1.2" opacity=".21"/></g>
                <circle r="17" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-planet="neptune" style="--orbit-start: 210deg; --orbit-end: 570deg; --counter-start: -210deg; --counter-end: -570deg; --orbit-period: 146s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(210)">
          <g transform="translate(660 0)">
            <g class="hud-counter" transform="rotate(-210)">
              <g transform="scale(1 2.5)">
                <circle r="17" fill="url(#go-hud-neptune)"/>
                <g clip-path="url(#go-hud-neptune-clip)"><path d="M-20 -6 Q0 -3 20 -7 M-20 5 Q0 8 20 3" fill="none" stroke="#9cb9ec" stroke-width="1.4" opacity=".32"/><ellipse cx="5" cy="4" rx="4" ry="1.8" fill="#1a3e83" opacity=".65"/></g>
                <circle r="17" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    </g>
</svg>
<canvas class="hud-sun" aria-hidden="true"></canvas>
<svg class="hud-solar hud-solar-near" viewBox="-690 -290 1380 580" role="img" aria-label="GO" focusable="false">
    <g fill="none" stroke="#5b7c99" stroke-width="1.5" stroke-opacity=".5" aria-hidden="true">
        <path d="M-286 0A286 114.4 0 0 0 286 0"/>
        <path d="M-330 0A330 132.0 0 0 0 330 0"/>
        <path d="M-378 0A378 151.2 0 0 0 378 0"/>
        <path d="M-424 0A424 169.6 0 0 0 424 0"/>
        <path d="M-510 0A510 204.0 0 0 0 510 0"/>
        <path d="M-566 0A566 226.4 0 0 0 566 0"/>
        <path d="M-616 0A616 246.4 0 0 0 616 0"/>
        <path d="M-660 0A660 264.0 0 0 0 660 0"/>
    </g>
    <g fill="#b8a18b" opacity=".35" aria-hidden="true"><circle cx="-467.8" cy="22.1" r="1.1"/><circle cx="383.1" cy="102.9" r="1.2"/><circle cx="-102.0" cy="182.0" r="1.8"/><circle cx="-33.8" cy="189.1" r="1.4"/><circle cx="429.7" cy="86.9" r="1.5"/><circle cx="-7.5" cy="182.4" r="1.8"/><circle cx="343.9" cy="125.3" r="1.2"/><circle cx="-115.1" cy="175.7" r="1.7"/><circle cx="-166.8" cy="168.4" r="1.5"/><circle cx="441.3" cy="49.0" r="1.4"/><circle cx="-362.0" cy="118.6" r="1.3"/><circle cx="-99.7" cy="184.8" r="1.1"/><circle cx="-463.1" cy="29.7" r="1.8"/><circle cx="-112.1" cy="183.0" r="1.1"/><circle cx="282.9" cy="154.4" r="1.5"/><circle cx="-457.2" cy="50.5" r="1.4"/><circle cx="-3.1" cy="190.9" r="1.8"/><circle cx="-437.6" cy="49.9" r="1.0"/><circle cx="-360.1" cy="121.2" r="1.8"/><circle cx="-341.1" cy="120.8" r="1.6"/><circle cx="454.0" cy="41.4" r="1.6"/><circle cx="-384.8" cy="115.7" r="1.9"/><circle cx="-469.7" cy="26.1" r="1.1"/><circle cx="170.9" cy="177.7" r="1.6"/><circle cx="-259.7" cy="161.4" r="1.9"/><circle cx="-480.7" cy="2.2" r="1.3"/><circle cx="-480.0" cy="16.0" r="1.7"/><circle cx="463.5" cy="28.1" r="1.9"/><circle cx="283.2" cy="151.9" r="1.7"/><circle cx="-252.4" cy="152.5" r="1.5"/><circle cx="93.7" cy="178.3" r="1.7"/><circle cx="-315.6" cy="136.0" r="1.1"/><circle cx="409.2" cy="100.8" r="1.2"/></g>
    <g class="hud-logo" fill="#fd7201" fill-rule="evenodd" transform="translate(-150 -68.75) scale(.28355)"><path d="M221 16 L349 16 L349 17 L358 17 L358 18 L364 18 L364 19 L369 19 L369 20 L374 20 L374 21 L382 22 L382 23 L385 23 L385 24 L388 24 L388 25 L391 25 L391 26 L397 27 L397 28 L399 28 L399 29 L402 29 L402 30 L404 30 L404 31 L406 31 L406 32 L409 32 L409 33 L411 33 L411 34 L413 34 L413 35 L415 35 L415 36 L417 36 L417 37 L419 37 L419 38 L425 40 L426 42 L428 42 L428 43 L430 43 L431 45 L435 46 L435 47 L438 48 L439 50 L441 50 L443 53 L445 53 L445 54 L448 55 L450 58 L452 58 L454 61 L456 61 L456 62 L457 62 L461 67 L463 67 L463 68 L464 68 L464 69 L465 69 L465 70 L466 70 L466 71 L467 71 L467 72 L468 72 L473 78 L475 78 L475 79 L479 82 L479 84 L480 84 L480 85 L481 85 L481 86 L482 86 L482 87 L483 87 L483 88 L489 93 L489 95 L494 99 L494 101 L498 104 L498 106 L500 107 L500 109 L503 111 L503 113 L506 115 L506 117 L507 117 L508 120 L510 121 L511 125 L513 126 L514 130 L516 131 L517 135 L519 136 L519 138 L520 138 L522 144 L524 145 L524 147 L525 147 L525 149 L526 149 L526 151 L527 151 L527 153 L528 153 L528 155 L529 155 L529 157 L530 157 L530 159 L531 159 L531 162 L532 162 L532 164 L533 164 L533 166 L534 166 L534 168 L535 168 L535 170 L536 170 L536 172 L537 172 L537 174 L391 174 L391 173 L389 172 L389 170 L387 169 L387 167 L384 165 L384 163 L383 163 L383 162 L382 162 L382 161 L381 161 L381 160 L380 160 L380 159 L379 159 L379 158 L378 158 L373 152 L371 152 L369 149 L367 149 L367 148 L364 147 L363 145 L361 145 L361 144 L359 144 L359 143 L357 143 L357 142 L355 142 L355 141 L353 141 L353 140 L350 140 L350 139 L348 139 L348 138 L345 138 L345 137 L341 137 L341 136 L337 136 L337 135 L330 135 L330 134 L231 134 L231 135 L217 136 L217 137 L213 137 L213 138 L210 138 L210 139 L203 140 L203 141 L201 141 L201 142 L198 142 L198 143 L196 143 L196 144 L194 144 L194 145 L192 145 L192 146 L186 148 L186 149 L183 150 L182 152 L178 153 L178 154 L177 154 L176 156 L174 156 L171 160 L169 160 L169 161 L168 161 L168 162 L167 162 L167 163 L166 163 L166 164 L165 164 L165 165 L164 165 L164 166 L158 171 L158 173 L154 176 L154 178 L151 180 L151 182 L150 182 L149 185 L147 186 L147 188 L146 188 L146 190 L145 190 L145 192 L144 192 L144 194 L143 194 L143 196 L142 196 L142 198 L141 198 L141 200 L140 200 L140 202 L139 202 L139 205 L138 205 L138 208 L137 208 L137 210 L136 210 L136 214 L135 214 L135 217 L134 217 L133 228 L132 228 L132 257 L133 257 L133 264 L134 264 L135 272 L136 272 L136 275 L137 275 L138 281 L139 281 L139 283 L140 283 L140 286 L141 286 L141 288 L142 288 L142 290 L143 290 L145 296 L147 297 L148 301 L150 302 L150 304 L152 305 L152 307 L155 309 L155 311 L160 315 L160 317 L161 317 L165 322 L167 322 L172 328 L174 328 L177 332 L179 332 L180 334 L184 335 L185 337 L187 337 L187 338 L189 338 L189 339 L191 339 L191 340 L193 340 L193 341 L195 341 L195 342 L197 342 L197 343 L199 343 L199 344 L205 345 L205 346 L207 346 L207 347 L211 347 L211 348 L219 349 L219 350 L225 350 L225 351 L332 351 L332 350 L337 350 L337 349 L340 349 L340 348 L344 348 L344 347 L346 347 L346 346 L349 346 L349 345 L351 345 L351 344 L357 342 L358 340 L360 340 L361 338 L363 338 L364 336 L366 336 L370 331 L372 331 L372 330 L377 326 L377 324 L382 320 L382 318 L384 317 L384 315 L385 315 L386 312 L388 311 L388 309 L389 309 L389 307 L390 307 L390 305 L391 305 L391 303 L392 303 L392 300 L393 300 L393 298 L394 298 L394 295 L395 295 L395 293 L394 293 L394 291 L393 291 L392 289 L252 289 L252 217 L549 217 L550 231 L551 231 L551 244 L552 244 L552 260 L551 260 L551 273 L550 273 L549 285 L548 285 L548 289 L547 289 L547 293 L546 293 L546 297 L545 297 L545 300 L544 300 L543 306 L542 306 L542 308 L541 308 L541 310 L540 310 L540 312 L539 312 L539 314 L538 314 L536 320 L534 321 L534 323 L533 323 L531 329 L529 330 L529 332 L528 332 L528 334 L527 334 L525 340 L524 340 L523 343 L521 344 L519 350 L517 351 L517 353 L515 354 L514 358 L512 359 L512 361 L510 362 L510 364 L507 366 L507 368 L505 369 L505 371 L502 373 L502 375 L501 375 L501 376 L498 378 L498 380 L495 382 L495 384 L494 384 L494 385 L488 390 L488 392 L487 392 L487 393 L486 393 L486 394 L485 394 L485 395 L484 395 L484 396 L483 396 L483 397 L482 397 L482 398 L481 398 L481 399 L480 399 L480 400 L479 400 L479 401 L478 401 L478 402 L477 402 L477 403 L476 403 L476 404 L475 404 L475 405 L474 405 L474 406 L473 406 L473 407 L472 407 L467 413 L465 413 L460 419 L458 419 L455 423 L453 423 L451 426 L449 426 L447 429 L445 429 L443 432 L441 432 L441 433 L438 434 L437 436 L433 437 L432 439 L428 440 L427 442 L423 443 L422 445 L420 445 L420 446 L418 446 L418 447 L416 447 L416 448 L414 448 L414 449 L412 449 L412 450 L410 450 L410 451 L408 451 L408 452 L405 452 L405 453 L403 453 L403 454 L401 454 L401 455 L395 456 L395 457 L393 457 L393 458 L390 458 L390 459 L387 459 L387 460 L384 460 L384 461 L381 461 L381 462 L378 462 L378 463 L374 463 L374 464 L370 464 L370 465 L365 465 L365 466 L360 466 L360 467 L354 467 L354 468 L345 468 L345 469 L220 469 L220 468 L210 468 L210 467 L204 467 L204 466 L194 465 L194 464 L190 464 L190 463 L182 462 L182 461 L179 461 L179 460 L176 460 L176 459 L173 459 L173 458 L167 457 L167 456 L165 456 L165 455 L162 455 L162 454 L160 454 L160 453 L157 453 L157 452 L155 452 L155 451 L152 451 L152 450 L150 450 L150 449 L148 449 L148 448 L146 448 L146 447 L144 447 L144 446 L138 444 L137 442 L133 441 L132 439 L126 437 L125 435 L123 435 L122 433 L120 433 L119 431 L117 431 L116 429 L114 429 L112 426 L110 426 L108 423 L106 423 L106 422 L105 422 L103 419 L101 419 L97 414 L95 414 L95 413 L94 413 L94 412 L93 412 L93 411 L92 411 L92 410 L91 410 L91 409 L90 409 L90 408 L89 408 L84 402 L82 402 L82 401 L80 400 L80 398 L79 398 L79 397 L78 397 L78 396 L77 396 L77 395 L71 390 L71 388 L66 384 L66 382 L62 379 L62 377 L61 377 L60 374 L57 372 L57 370 L55 369 L55 367 L52 365 L51 361 L49 360 L49 358 L48 358 L48 356 L46 355 L44 349 L42 348 L42 346 L41 346 L41 344 L40 344 L40 342 L39 342 L39 340 L38 340 L38 338 L37 338 L37 336 L36 336 L36 333 L35 333 L35 331 L34 331 L34 329 L33 329 L33 326 L32 326 L32 324 L31 324 L31 321 L30 321 L29 316 L28 316 L28 313 L27 313 L27 309 L26 309 L26 306 L25 306 L25 303 L24 303 L24 299 L23 299 L23 295 L22 295 L22 291 L21 291 L21 287 L20 287 L20 281 L19 281 L19 275 L18 275 L18 268 L17 268 L17 254 L16 254 L16 227 L17 227 L18 208 L19 208 L19 203 L20 203 L21 193 L22 193 L22 189 L23 189 L24 181 L25 181 L25 178 L26 178 L26 174 L27 174 L28 168 L29 168 L29 166 L30 166 L31 160 L32 160 L32 158 L33 158 L33 155 L34 155 L34 153 L35 153 L35 151 L36 151 L36 149 L37 149 L37 146 L38 146 L40 140 L42 139 L42 137 L43 137 L43 135 L44 135 L46 129 L47 129 L48 126 L50 125 L51 121 L53 120 L53 118 L55 117 L55 115 L57 114 L57 112 L58 112 L58 111 L60 110 L60 108 L63 106 L63 104 L64 104 L64 103 L67 101 L67 99 L73 94 L73 92 L74 92 L74 91 L75 91 L75 90 L76 90 L76 89 L77 89 L77 88 L78 88 L78 87 L79 87 L79 86 L80 86 L80 85 L81 85 L81 84 L82 84 L82 83 L83 83 L83 82 L84 82 L84 81 L85 81 L85 80 L86 80 L86 79 L87 79 L92 73 L94 73 L99 67 L101 67 L104 63 L106 63 L108 60 L110 60 L112 57 L114 57 L115 55 L117 55 L118 53 L120 53 L121 51 L123 51 L123 50 L126 49 L127 47 L131 46 L132 44 L134 44 L134 43 L136 43 L136 42 L138 42 L138 41 L140 41 L140 40 L142 40 L142 39 L144 39 L144 38 L146 38 L146 37 L148 37 L148 36 L150 36 L150 35 L152 35 L152 34 L154 34 L154 33 L156 33 L156 32 L159 32 L159 31 L161 31 L161 30 L167 29 L167 28 L169 28 L169 27 L172 27 L172 26 L175 26 L175 25 L178 25 L178 24 L182 24 L182 23 L186 23 L186 22 L190 22 L190 21 L194 21 L194 20 L199 20 L199 19 L205 19 L205 18 L211 18 L211 17 L221 17 Z"/><path d="M715 16 L843 16 L843 17 L858 18 L858 19 L863 19 L863 20 L867 20 L867 21 L875 22 L875 23 L878 23 L878 24 L881 24 L881 25 L887 26 L887 27 L889 27 L889 28 L895 29 L895 30 L897 30 L897 31 L899 31 L899 32 L901 32 L901 33 L904 33 L904 34 L906 34 L906 35 L908 35 L908 36 L910 36 L910 37 L916 39 L917 41 L923 43 L924 45 L928 46 L929 48 L931 48 L932 50 L934 50 L935 52 L937 52 L938 54 L940 54 L942 57 L944 57 L945 59 L947 59 L950 63 L952 63 L956 68 L958 68 L958 69 L959 69 L959 70 L960 70 L960 71 L961 71 L961 72 L962 72 L967 78 L969 78 L969 79 L975 84 L975 86 L976 86 L976 87 L977 87 L977 88 L978 88 L978 89 L984 94 L984 96 L990 101 L990 103 L993 105 L993 107 L996 109 L996 111 L998 112 L998 114 L1001 116 L1001 118 L1003 119 L1003 121 L1004 121 L1005 124 L1007 125 L1008 129 L1010 130 L1012 136 L1014 137 L1014 139 L1015 139 L1015 141 L1016 141 L1016 143 L1017 143 L1017 145 L1018 145 L1018 147 L1019 147 L1019 149 L1020 149 L1020 151 L1021 151 L1021 153 L1022 153 L1022 156 L1023 156 L1023 158 L1024 158 L1024 160 L1025 160 L1025 162 L1026 162 L1026 165 L1027 165 L1027 167 L1028 167 L1028 170 L1029 170 L1029 173 L1030 173 L1030 176 L1031 176 L1031 179 L1032 179 L1032 182 L1033 182 L1033 185 L1034 185 L1034 189 L1035 189 L1035 193 L1036 193 L1036 197 L1037 197 L1037 201 L1038 201 L1038 206 L1039 206 L1040 220 L1041 220 L1041 231 L1042 231 L1042 250 L1041 250 L1040 269 L1039 269 L1039 275 L1038 275 L1038 280 L1037 280 L1037 285 L1036 285 L1035 293 L1034 293 L1034 296 L1033 296 L1033 300 L1032 300 L1032 303 L1031 303 L1031 306 L1030 306 L1030 309 L1029 309 L1029 312 L1028 312 L1027 318 L1026 318 L1026 320 L1025 320 L1025 322 L1024 322 L1024 325 L1023 325 L1023 327 L1022 327 L1022 329 L1021 329 L1021 332 L1020 332 L1020 334 L1019 334 L1019 336 L1018 336 L1018 338 L1017 338 L1017 340 L1016 340 L1016 342 L1015 342 L1013 348 L1011 349 L1009 355 L1007 356 L1006 360 L1004 361 L1004 363 L1002 364 L1002 366 L1000 367 L1000 369 L997 371 L997 373 L995 374 L995 376 L994 376 L994 377 L992 378 L992 380 L987 384 L987 386 L981 391 L981 393 L980 393 L980 394 L979 394 L979 395 L978 395 L978 396 L977 396 L977 397 L976 397 L976 398 L975 398 L975 399 L974 399 L974 400 L973 400 L973 401 L972 401 L972 402 L971 402 L971 403 L970 403 L970 404 L969 404 L964 410 L962 410 L962 411 L961 411 L956 417 L954 417 L954 418 L953 418 L951 421 L949 421 L946 425 L944 425 L942 428 L940 428 L939 430 L937 430 L936 432 L934 432 L933 434 L931 434 L930 436 L928 436 L928 437 L925 438 L924 440 L922 440 L922 441 L916 443 L915 445 L913 445 L913 446 L911 446 L911 447 L909 447 L909 448 L907 448 L907 449 L905 449 L905 450 L903 450 L903 451 L901 451 L901 452 L898 452 L898 453 L896 453 L896 454 L894 454 L894 455 L892 455 L892 456 L886 457 L886 458 L884 458 L884 459 L881 459 L881 460 L878 460 L878 461 L875 461 L875 462 L871 462 L871 463 L868 463 L868 464 L864 464 L864 465 L859 465 L859 466 L854 466 L854 467 L848 467 L848 468 L839 468 L839 469 L713 469 L713 468 L705 468 L705 467 L699 467 L699 466 L694 466 L694 465 L689 465 L689 464 L685 464 L685 463 L677 462 L677 461 L674 461 L674 460 L671 460 L671 459 L665 458 L665 457 L663 457 L663 456 L660 456 L660 455 L658 455 L658 454 L655 454 L655 453 L653 453 L653 452 L651 452 L651 451 L648 451 L648 450 L646 450 L646 449 L644 449 L644 448 L638 446 L637 444 L631 442 L630 440 L626 439 L625 437 L623 437 L623 436 L621 436 L620 434 L618 434 L617 432 L615 432 L613 429 L611 429 L611 428 L608 427 L605 423 L603 423 L600 419 L598 419 L598 418 L597 418 L592 412 L590 412 L590 411 L589 411 L589 410 L588 410 L588 409 L587 409 L587 408 L586 408 L586 407 L585 407 L585 406 L584 406 L584 405 L583 405 L583 404 L582 404 L582 403 L581 403 L581 402 L580 402 L580 401 L574 396 L574 394 L573 394 L573 393 L569 390 L569 388 L564 384 L564 382 L561 380 L561 378 L559 377 L559 375 L556 373 L556 371 L554 370 L554 368 L553 368 L552 365 L550 364 L550 362 L549 362 L549 360 L547 359 L546 355 L544 354 L544 351 L545 351 L546 347 L548 346 L550 340 L552 339 L553 335 L555 334 L556 330 L558 329 L560 323 L562 322 L564 316 L566 315 L568 309 L569 309 L571 306 L756 306 L756 378 L758 378 L758 377 L760 377 L762 374 L764 374 L768 369 L770 369 L772 366 L774 366 L778 361 L780 361 L782 358 L784 358 L787 354 L789 354 L792 350 L794 350 L797 346 L799 346 L802 342 L804 342 L807 338 L809 338 L809 337 L810 337 L811 335 L813 335 L816 331 L818 331 L822 326 L824 326 L824 325 L825 325 L826 323 L828 323 L831 319 L833 319 L836 315 L838 315 L841 311 L843 311 L846 307 L848 307 L851 303 L853 303 L856 299 L858 299 L861 295 L863 295 L866 291 L868 291 L871 287 L873 287 L876 283 L878 283 L881 279 L883 279 L886 275 L888 275 L891 271 L893 271 L896 267 L898 267 L902 262 L904 262 L907 258 L909 258 L912 254 L914 254 L914 253 L915 253 L918 249 L920 249 L924 244 L926 244 L926 243 L928 242 L928 241 L927 241 L925 238 L923 238 L919 233 L917 233 L913 228 L911 228 L909 225 L907 225 L904 221 L902 221 L899 217 L897 217 L893 212 L891 212 L891 211 L890 211 L889 209 L887 209 L884 205 L882 205 L877 199 L875 199 L872 195 L870 195 L868 192 L866 192 L862 187 L860 187 L858 184 L856 184 L853 180 L851 180 L848 176 L846 176 L843 172 L841 172 L838 168 L836 168 L832 163 L830 163 L828 160 L826 160 L822 155 L820 155 L818 152 L816 152 L813 148 L811 148 L808 144 L806 144 L803 140 L801 140 L798 136 L796 136 L793 132 L791 132 L788 128 L786 128 L784 125 L782 125 L779 121 L777 121 L773 116 L771 116 L769 113 L767 113 L765 110 L763 110 L763 109 L762 109 L760 106 L758 106 L757 104 L756 104 L756 177 L570 177 L570 176 L568 175 L566 169 L564 168 L563 164 L561 163 L559 157 L557 156 L555 150 L553 149 L552 145 L550 144 L550 142 L549 142 L549 140 L548 140 L548 138 L546 137 L546 135 L545 135 L544 132 L545 132 L545 130 L547 129 L547 127 L548 127 L548 125 L550 124 L550 122 L551 122 L551 120 L553 119 L553 117 L555 116 L555 114 L556 114 L556 113 L558 112 L558 110 L560 109 L560 107 L563 105 L563 103 L566 101 L566 99 L571 95 L571 93 L572 93 L572 92 L573 92 L573 91 L574 91 L574 90 L580 85 L580 83 L581 83 L584 79 L586 79 L586 78 L587 78 L587 77 L588 77 L588 76 L589 76 L589 75 L590 75 L590 74 L591 74 L596 68 L598 68 L602 63 L604 63 L607 59 L609 59 L610 57 L612 57 L614 54 L616 54 L617 52 L619 52 L619 51 L622 50 L623 48 L627 47 L628 45 L632 44 L633 42 L635 42 L635 41 L637 41 L637 40 L639 40 L639 39 L641 39 L641 38 L643 38 L643 37 L645 37 L645 36 L647 36 L647 35 L649 35 L649 34 L651 34 L651 33 L653 33 L653 32 L656 32 L656 31 L658 31 L658 30 L661 30 L661 29 L663 29 L663 28 L666 28 L666 27 L669 27 L669 26 L672 26 L672 25 L675 25 L675 24 L678 24 L678 23 L682 23 L682 22 L686 22 L686 21 L690 21 L690 20 L695 20 L695 19 L700 19 L700 18 L715 17 Z"/></g>
    <g clip-path="url(#go-hud-near)" aria-hidden="true">
        <g data-solar-near="mercury" style="--orbit-start: -35deg; --orbit-end: 325deg; --counter-start: 35deg; --counter-end: -325deg; --orbit-period: 24s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(-35)">
          <g transform="translate(286 0)">
            <g class="hud-counter" transform="rotate(35)">
              <g transform="scale(1 2.5)">
                <circle r="7" fill="url(#go-hud-mercury)"/>
                <g clip-path="url(#go-hud-mercury-clip)"><g fill="#494541" opacity=".37"><circle cx="-2.8" cy="-2.4" r="1.6"/><circle cx="2.8" cy="1.8" r="1.2"/><circle cx="-1" cy="4" r=".7"/></g><path d="M-4 -1 Q-2 -3 0 -2" fill="none" stroke="#f1dfc8" stroke-width=".5" opacity=".45"/></g>
                <circle r="7" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="venus" style="--orbit-start: 132deg; --orbit-end: 492deg; --counter-start: -132deg; --counter-end: -492deg; --orbit-period: 34s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(132)">
          <g transform="translate(330 0)">
            <g class="hud-counter" transform="rotate(-132)">
              <g transform="scale(1 2.5)">
                <circle r="11.5" fill="url(#go-hud-venus)"/>
                <g clip-path="url(#go-hud-venus-clip)"><path d="M-14 -4 Q-3 -9 13 -3 M-13 2 Q0 -3 13 3 M-10 8 Q0 3 12 8" fill="none" stroke="#f9e4b8" stroke-width="2" opacity=".5"/></g>
                <circle r="11.5" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="earth" style="--orbit-start: 45deg; --orbit-end: 405deg; --counter-start: -45deg; --counter-end: -405deg; --orbit-period: 46s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(45)">
          <g transform="translate(378 0)">
            <g class="hud-counter" transform="rotate(-45)">
              <g transform="scale(1 2.5)">
                <circle r="12.5" fill="url(#go-hud-earth)"/>
                <g clip-path="url(#go-hud-earth-clip)"><path d="M-10 -7 L-5 -10 -1 -7 -2 -3 2 -1 0 4 -3 7 -4 3 -7 1 -5 -3 -10 -4 Z M4 -9 L9 -7 12 -1 7 0 4 -3 2 -4 Z M6 6 L11 4 12 8 7 10 5 8 Z" fill="#74ad68"/><path d="M-10 -2 Q-2 -8 7 -5 M-5 9 Q1 4 11 4" fill="none" stroke="#e8f7ff" stroke-width="1.3" opacity=".65"/><path d="M-6 -11 Q0 -14 6 -10 L1 -9 Z" fill="#e3f5ff"/></g>
                <circle r="12.5" fill="url(#go-hud-planet-shade)"/>
                <circle cx="21" cy="-5" r="2.8" fill="#bfc3c9"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="mars" style="--orbit-start: 220deg; --orbit-end: 580deg; --counter-start: -220deg; --counter-end: -580deg; --orbit-period: 58s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(220)">
          <g transform="translate(424 0)">
            <g class="hud-counter" transform="rotate(-220)">
              <g transform="scale(1 2.5)">
                <circle r="9" fill="url(#go-hud-mars)"/>
                <g clip-path="url(#go-hud-mars-clip)"><path d="M-7 -2 Q-1 -6 3 -2 L6 1 2 4 -2 2 -5 4 Z" fill="#703b2b" opacity=".55"/><ellipse cy="-8" rx="3.5" ry="1.5" fill="#e9dcd0" opacity=".8"/></g>
                <circle r="9" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="jupiter" style="--orbit-start: -65deg; --orbit-end: 295deg; --counter-start: 65deg; --counter-end: -295deg; --orbit-period: 76s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(-65)">
          <g transform="translate(510 0)">
            <g class="hud-counter" transform="rotate(65)">
              <g transform="scale(1 2.5)">
                <circle r="28" fill="url(#go-hud-jupiter)"/>
                <g clip-path="url(#go-hud-jupiter-clip)"><path d="M-34 -17 Q-2 -12 34 -18 L34 -12 Q0 -7 -34 -11 Z" fill="#b67d54"/><path d="M-34 -6 Q-4 -1 34 -7 L34 -1 Q0 3 -34 0 Z" fill="#f3d5a9"/><path d="M-33 4 Q0 9 33 3 L33 10 Q0 15 -33 10 Z" fill="#a96643"/><path d="M-31 15 Q0 20 31 15 L31 20 Q0 26 -31 21 Z" fill="#e4c18c"/><path d="M-30 -23 Q0 -19 30 -24 M-30 1 Q0 6 30 0" fill="none" stroke="#7e523b" stroke-width="1.2" opacity=".45"/><ellipse cx="11" cy="8" rx="6" ry="3.3" fill="#b7684f"/><ellipse cx="10" cy="7.5" rx="3.9" ry="2" fill="#d38c68"/></g>
                <circle r="28" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="saturn" style="--orbit-start: 150deg; --orbit-end: 510deg; --counter-start: -150deg; --counter-end: -510deg; --orbit-period: 98s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(150)">
          <g transform="translate(566 0)">
            <g class="hud-counter" transform="rotate(-150)">
              <g transform="scale(1 2.5)">
                <g transform="rotate(-22)" fill="none"><ellipse rx="46" ry="14" stroke="#bda87c" stroke-width="7" opacity=".75"/><ellipse rx="39" ry="11.8" stroke="#e3d4a7" stroke-width="2.6" opacity=".85"/></g>
                <circle r="23" fill="url(#go-hud-saturn)"/>
                <g clip-path="url(#go-hud-saturn-clip)"><path d="M-27 -11 Q0 -7 27 -12 M-27 -3 Q0 1 27 -4 M-27 5 Q0 9 27 4 M-26 13 Q0 17 26 12" fill="none" stroke="#a58c5b" stroke-width="2.2" opacity=".35"/></g>
                <circle r="23" fill="url(#go-hud-planet-shade)"/>
                <g transform="rotate(-22)" fill="none"><path d="M-46 0 A46 14 0 0 0 46 0" stroke="#d8c698" stroke-width="6"/><path d="M-39 0 A39 11.8 0 0 0 39 0" stroke="#f0dfb2" stroke-width="1.7"/></g>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="uranus" style="--orbit-start: 25deg; --orbit-end: 385deg; --counter-start: -25deg; --counter-end: -385deg; --orbit-period: 122s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(25)">
          <g transform="translate(616 0)">
            <g class="hud-counter" transform="rotate(-25)">
              <g transform="scale(1 2.5)">
                <circle r="17" fill="url(#go-hud-uranus)"/>
                <g clip-path="url(#go-hud-uranus-clip)"><path d="M-19 -5 Q0 -1 19 -5 M-19 4 Q0 8 19 4" fill="none" stroke="#d4f4e8" stroke-width="1.2" opacity=".21"/></g>
                <circle r="17" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
        <g data-solar-near="neptune" style="--orbit-start: 210deg; --orbit-end: 570deg; --counter-start: -210deg; --counter-end: -570deg; --orbit-period: 146s;">
      <g transform="scale(1 .4)">
        <g class="hud-rotor" transform="rotate(210)">
          <g transform="translate(660 0)">
            <g class="hud-counter" transform="rotate(-210)">
              <g transform="scale(1 2.5)">
                <circle r="17" fill="url(#go-hud-neptune)"/>
                <g clip-path="url(#go-hud-neptune-clip)"><path d="M-20 -6 Q0 -3 20 -7 M-20 5 Q0 8 20 3" fill="none" stroke="#9cb9ec" stroke-width="1.4" opacity=".32"/><ellipse cx="5" cy="4" rx="4" ry="1.8" fill="#1a3e83" opacity=".65"/></g>
                <circle r="17" fill="url(#go-hud-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    </g>
</svg>

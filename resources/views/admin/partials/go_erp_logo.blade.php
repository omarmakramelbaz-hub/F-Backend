@php
    $goErpLogoId = 'go-erp-' . substr(sha1($logoClass ?? 'logo'), 0, 10);
    $goErpIsLogin = strpos($logoClass ?? '', 'dashboard-login-logo') !== false;
    $goErpIsSidebar = strpos($logoClass ?? '', 'dashboard-brand-logo') !== false;
    $goErpViewBox = $goErpIsLogin ? '0 0 1061 1103' : ($goErpIsSidebar ? '0 0 1420 700' : '0 0 1061 623');
    $goErpSceneTransform = $goErpIsLogin ? 'translate(0 480)' : 'translate(0 0)';
    $goErpSolarTransform = $goErpIsSidebar ? 'translate(290 475)' : 'translate(0 0)';
    $goErpPlanetStroke = $goErpIsSidebar ? '#071321' : 'none';
    $goErpCompactTransform = $goErpIsSidebar ? 'translate(0 -66.91) scale(1.33836)' : 'translate(0 240)';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" class="{{ $logoClass ?? 'go-erp-platform-logo' }}" data-go-erp-vector="1" data-go-erp-explosion="1" data-go-erp-dynamic="1" data-go-erp-solar="1" color="#fd7201" viewBox="{{ $goErpViewBox }}" role="img" aria-label="GO ERP SYSTEM" focusable="false">
  <title>GO ERP SYSTEM</title>
  <defs>
    <radialGradient id="{{ $goErpLogoId }}-sun-glow">
      <stop offset="0" stop-color="#ffc950" stop-opacity=".32"/><stop offset=".4" stop-color="#fb971f" stop-opacity=".12"/><stop offset="1" stop-color="#ff7a09" stop-opacity="0"/>
    </radialGradient>
    <radialGradient id="{{ $goErpLogoId }}-sun" cx=".35" cy=".3" r=".75">
      <stop offset="0" stop-color="#fff2ad"/><stop offset=".35" stop-color="#ffd36a"/><stop offset=".75" stop-color="#ffa42e"/><stop offset="1" stop-color="#eb6511"/>
    </radialGradient>
    <radialGradient id="{{ $goErpLogoId }}-planet-shade" cx=".28" cy=".25" r=".85">
      <stop offset="0" stop-color="#ffffff" stop-opacity=".13"/><stop offset=".45" stop-color="#000000" stop-opacity="0"/><stop offset=".78" stop-color="#000000" stop-opacity=".18"/><stop offset="1" stop-color="#000000" stop-opacity=".65"/>
    </radialGradient>
    <radialGradient id="{{ $goErpLogoId }}-mercury" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#d7cdc0"/><stop offset=".6" stop-color="#9a9187"/><stop offset="1" stop-color="#4a4541"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-mercury-clip" clipPathUnits="userSpaceOnUse"><circle r="7"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-venus" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#fff0c6"/><stop offset=".6" stop-color="#d5b57e"/><stop offset="1" stop-color="#77512e"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-venus-clip" clipPathUnits="userSpaceOnUse"><circle r="11.5"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-earth" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#80d4f4"/><stop offset=".6" stop-color="#2079bc"/><stop offset="1" stop-color="#072742"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-earth-clip" clipPathUnits="userSpaceOnUse"><circle r="12.5"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-mars" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#f3aa76"/><stop offset=".6" stop-color="#b75432"/><stop offset="1" stop-color="#472218"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-mars-clip" clipPathUnits="userSpaceOnUse"><circle r="9"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-jupiter" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#f3dfbe"/><stop offset=".6" stop-color="#bd9368"/><stop offset="1" stop-color="#4f3e32"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-jupiter-clip" clipPathUnits="userSpaceOnUse"><circle r="28"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-saturn" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#fae9b7"/><stop offset=".6" stop-color="#cbb47d"/><stop offset="1" stop-color="#66543a"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-saturn-clip" clipPathUnits="userSpaceOnUse"><circle r="23"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-uranus" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#ccf9f3"/><stop offset=".6" stop-color="#74bdc5"/><stop offset="1" stop-color="#245b6b"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-uranus-clip" clipPathUnits="userSpaceOnUse"><circle r="17"/></clipPath>
    <radialGradient id="{{ $goErpLogoId }}-neptune" cx=".3" cy=".25" r=".85">
      <stop offset="0" stop-color="#83b7f5"/><stop offset=".6" stop-color="#3568bb"/><stop offset="1" stop-color="#11284f"/>
    </radialGradient>
    <clipPath id="{{ $goErpLogoId }}-neptune-clip" clipPathUnits="userSpaceOnUse"><circle r="17"/></clipPath>
    <g id="{{ $goErpLogoId }}-shape" fill="currentColor" fill-rule="evenodd">
    <path d="M221 16 L349 16 L349 17 L358 17 L358 18 L364 18 L364 19 L369 19 L369 20 L374 20 L374 21 L382 22 L382 23 L385 23 L385 24 L388 24 L388 25 L391 25 L391 26 L397 27 L397 28 L399 28 L399 29 L402 29 L402 30 L404 30 L404 31 L406 31 L406 32 L409 32 L409 33 L411 33 L411 34 L413 34 L413 35 L415 35 L415 36 L417 36 L417 37 L419 37 L419 38 L425 40 L426 42 L428 42 L428 43 L430 43 L431 45 L435 46 L435 47 L438 48 L439 50 L441 50 L443 53 L445 53 L445 54 L448 55 L450 58 L452 58 L454 61 L456 61 L456 62 L457 62 L461 67 L463 67 L463 68 L464 68 L464 69 L465 69 L465 70 L466 70 L466 71 L467 71 L467 72 L468 72 L473 78 L475 78 L475 79 L479 82 L479 84 L480 84 L480 85 L481 85 L481 86 L482 86 L482 87 L483 87 L483 88 L489 93 L489 95 L494 99 L494 101 L498 104 L498 106 L500 107 L500 109 L503 111 L503 113 L506 115 L506 117 L507 117 L508 120 L510 121 L511 125 L513 126 L514 130 L516 131 L517 135 L519 136 L519 138 L520 138 L522 144 L524 145 L524 147 L525 147 L525 149 L526 149 L526 151 L527 151 L527 153 L528 153 L528 155 L529 155 L529 157 L530 157 L530 159 L531 159 L531 162 L532 162 L532 164 L533 164 L533 166 L534 166 L534 168 L535 168 L535 170 L536 170 L536 172 L537 172 L537 174 L391 174 L391 173 L389 172 L389 170 L387 169 L387 167 L384 165 L384 163 L383 163 L383 162 L382 162 L382 161 L381 161 L381 160 L380 160 L380 159 L379 159 L379 158 L378 158 L373 152 L371 152 L369 149 L367 149 L367 148 L364 147 L363 145 L361 145 L361 144 L359 144 L359 143 L357 143 L357 142 L355 142 L355 141 L353 141 L353 140 L350 140 L350 139 L348 139 L348 138 L345 138 L345 137 L341 137 L341 136 L337 136 L337 135 L330 135 L330 134 L231 134 L231 135 L217 136 L217 137 L213 137 L213 138 L210 138 L210 139 L203 140 L203 141 L201 141 L201 142 L198 142 L198 143 L196 143 L196 144 L194 144 L194 145 L192 145 L192 146 L186 148 L186 149 L183 150 L182 152 L178 153 L178 154 L177 154 L176 156 L174 156 L171 160 L169 160 L169 161 L168 161 L168 162 L167 162 L167 163 L166 163 L166 164 L165 164 L165 165 L164 165 L164 166 L158 171 L158 173 L154 176 L154 178 L151 180 L151 182 L150 182 L149 185 L147 186 L147 188 L146 188 L146 190 L145 190 L145 192 L144 192 L144 194 L143 194 L143 196 L142 196 L142 198 L141 198 L141 200 L140 200 L140 202 L139 202 L139 205 L138 205 L138 208 L137 208 L137 210 L136 210 L136 214 L135 214 L135 217 L134 217 L133 228 L132 228 L132 257 L133 257 L133 264 L134 264 L135 272 L136 272 L136 275 L137 275 L138 281 L139 281 L139 283 L140 283 L140 286 L141 286 L141 288 L142 288 L142 290 L143 290 L145 296 L147 297 L148 301 L150 302 L150 304 L152 305 L152 307 L155 309 L155 311 L160 315 L160 317 L161 317 L165 322 L167 322 L172 328 L174 328 L177 332 L179 332 L180 334 L184 335 L185 337 L187 337 L187 338 L189 338 L189 339 L191 339 L191 340 L193 340 L193 341 L195 341 L195 342 L197 342 L197 343 L199 343 L199 344 L205 345 L205 346 L207 346 L207 347 L211 347 L211 348 L219 349 L219 350 L225 350 L225 351 L332 351 L332 350 L337 350 L337 349 L340 349 L340 348 L344 348 L344 347 L346 347 L346 346 L349 346 L349 345 L351 345 L351 344 L357 342 L358 340 L360 340 L361 338 L363 338 L364 336 L366 336 L370 331 L372 331 L372 330 L377 326 L377 324 L382 320 L382 318 L384 317 L384 315 L385 315 L386 312 L388 311 L388 309 L389 309 L389 307 L390 307 L390 305 L391 305 L391 303 L392 303 L392 300 L393 300 L393 298 L394 298 L394 295 L395 295 L395 293 L394 293 L394 291 L393 291 L392 289 L252 289 L252 217 L549 217 L550 231 L551 231 L551 244 L552 244 L552 260 L551 260 L551 273 L550 273 L549 285 L548 285 L548 289 L547 289 L547 293 L546 293 L546 297 L545 297 L545 300 L544 300 L543 306 L542 306 L542 308 L541 308 L541 310 L540 310 L540 312 L539 312 L539 314 L538 314 L536 320 L534 321 L534 323 L533 323 L531 329 L529 330 L529 332 L528 332 L528 334 L527 334 L525 340 L524 340 L523 343 L521 344 L519 350 L517 351 L517 353 L515 354 L514 358 L512 359 L512 361 L510 362 L510 364 L507 366 L507 368 L505 369 L505 371 L502 373 L502 375 L501 375 L501 376 L498 378 L498 380 L495 382 L495 384 L494 384 L494 385 L488 390 L488 392 L487 392 L487 393 L486 393 L486 394 L485 394 L485 395 L484 395 L484 396 L483 396 L483 397 L482 397 L482 398 L481 398 L481 399 L480 399 L480 400 L479 400 L479 401 L478 401 L478 402 L477 402 L477 403 L476 403 L476 404 L475 404 L475 405 L474 405 L474 406 L473 406 L473 407 L472 407 L467 413 L465 413 L460 419 L458 419 L455 423 L453 423 L451 426 L449 426 L447 429 L445 429 L443 432 L441 432 L441 433 L438 434 L437 436 L433 437 L432 439 L428 440 L427 442 L423 443 L422 445 L420 445 L420 446 L418 446 L418 447 L416 447 L416 448 L414 448 L414 449 L412 449 L412 450 L410 450 L410 451 L408 451 L408 452 L405 452 L405 453 L403 453 L403 454 L401 454 L401 455 L395 456 L395 457 L393 457 L393 458 L390 458 L390 459 L387 459 L387 460 L384 460 L384 461 L381 461 L381 462 L378 462 L378 463 L374 463 L374 464 L370 464 L370 465 L365 465 L365 466 L360 466 L360 467 L354 467 L354 468 L345 468 L345 469 L220 469 L220 468 L210 468 L210 467 L204 467 L204 466 L194 465 L194 464 L190 464 L190 463 L182 462 L182 461 L179 461 L179 460 L176 460 L176 459 L173 459 L173 458 L167 457 L167 456 L165 456 L165 455 L162 455 L162 454 L160 454 L160 453 L157 453 L157 452 L155 452 L155 451 L152 451 L152 450 L150 450 L150 449 L148 449 L148 448 L146 448 L146 447 L144 447 L144 446 L138 444 L137 442 L133 441 L132 439 L126 437 L125 435 L123 435 L122 433 L120 433 L119 431 L117 431 L116 429 L114 429 L112 426 L110 426 L108 423 L106 423 L106 422 L105 422 L103 419 L101 419 L97 414 L95 414 L95 413 L94 413 L94 412 L93 412 L93 411 L92 411 L92 410 L91 410 L91 409 L90 409 L90 408 L89 408 L84 402 L82 402 L82 401 L80 400 L80 398 L79 398 L79 397 L78 397 L78 396 L77 396 L77 395 L71 390 L71 388 L66 384 L66 382 L62 379 L62 377 L61 377 L60 374 L57 372 L57 370 L55 369 L55 367 L52 365 L51 361 L49 360 L49 358 L48 358 L48 356 L46 355 L44 349 L42 348 L42 346 L41 346 L41 344 L40 344 L40 342 L39 342 L39 340 L38 340 L38 338 L37 338 L37 336 L36 336 L36 333 L35 333 L35 331 L34 331 L34 329 L33 329 L33 326 L32 326 L32 324 L31 324 L31 321 L30 321 L29 316 L28 316 L28 313 L27 313 L27 309 L26 309 L26 306 L25 306 L25 303 L24 303 L24 299 L23 299 L23 295 L22 295 L22 291 L21 291 L21 287 L20 287 L20 281 L19 281 L19 275 L18 275 L18 268 L17 268 L17 254 L16 254 L16 227 L17 227 L18 208 L19 208 L19 203 L20 203 L21 193 L22 193 L22 189 L23 189 L24 181 L25 181 L25 178 L26 178 L26 174 L27 174 L28 168 L29 168 L29 166 L30 166 L31 160 L32 160 L32 158 L33 158 L33 155 L34 155 L34 153 L35 153 L35 151 L36 151 L36 149 L37 149 L37 146 L38 146 L40 140 L42 139 L42 137 L43 137 L43 135 L44 135 L46 129 L47 129 L48 126 L50 125 L51 121 L53 120 L53 118 L55 117 L55 115 L57 114 L57 112 L58 112 L58 111 L60 110 L60 108 L63 106 L63 104 L64 104 L64 103 L67 101 L67 99 L73 94 L73 92 L74 92 L74 91 L75 91 L75 90 L76 90 L76 89 L77 89 L77 88 L78 88 L78 87 L79 87 L79 86 L80 86 L80 85 L81 85 L81 84 L82 84 L82 83 L83 83 L83 82 L84 82 L84 81 L85 81 L85 80 L86 80 L86 79 L87 79 L92 73 L94 73 L99 67 L101 67 L104 63 L106 63 L108 60 L110 60 L112 57 L114 57 L115 55 L117 55 L118 53 L120 53 L121 51 L123 51 L123 50 L126 49 L127 47 L131 46 L132 44 L134 44 L134 43 L136 43 L136 42 L138 42 L138 41 L140 41 L140 40 L142 40 L142 39 L144 39 L144 38 L146 38 L146 37 L148 37 L148 36 L150 36 L150 35 L152 35 L152 34 L154 34 L154 33 L156 33 L156 32 L159 32 L159 31 L161 31 L161 30 L167 29 L167 28 L169 28 L169 27 L172 27 L172 26 L175 26 L175 25 L178 25 L178 24 L182 24 L182 23 L186 23 L186 22 L190 22 L190 21 L194 21 L194 20 L199 20 L199 19 L205 19 L205 18 L211 18 L211 17 L221 17 Z"/>
    <path d="M715 16 L843 16 L843 17 L858 18 L858 19 L863 19 L863 20 L867 20 L867 21 L875 22 L875 23 L878 23 L878 24 L881 24 L881 25 L887 26 L887 27 L889 27 L889 28 L895 29 L895 30 L897 30 L897 31 L899 31 L899 32 L901 32 L901 33 L904 33 L904 34 L906 34 L906 35 L908 35 L908 36 L910 36 L910 37 L916 39 L917 41 L923 43 L924 45 L928 46 L929 48 L931 48 L932 50 L934 50 L935 52 L937 52 L938 54 L940 54 L942 57 L944 57 L945 59 L947 59 L950 63 L952 63 L956 68 L958 68 L958 69 L959 69 L959 70 L960 70 L960 71 L961 71 L961 72 L962 72 L967 78 L969 78 L969 79 L975 84 L975 86 L976 86 L976 87 L977 87 L977 88 L978 88 L978 89 L984 94 L984 96 L990 101 L990 103 L993 105 L993 107 L996 109 L996 111 L998 112 L998 114 L1001 116 L1001 118 L1003 119 L1003 121 L1004 121 L1005 124 L1007 125 L1008 129 L1010 130 L1012 136 L1014 137 L1014 139 L1015 139 L1015 141 L1016 141 L1016 143 L1017 143 L1017 145 L1018 145 L1018 147 L1019 147 L1019 149 L1020 149 L1020 151 L1021 151 L1021 153 L1022 153 L1022 156 L1023 156 L1023 158 L1024 158 L1024 160 L1025 160 L1025 162 L1026 162 L1026 165 L1027 165 L1027 167 L1028 167 L1028 170 L1029 170 L1029 173 L1030 173 L1030 176 L1031 176 L1031 179 L1032 179 L1032 182 L1033 182 L1033 185 L1034 185 L1034 189 L1035 189 L1035 193 L1036 193 L1036 197 L1037 197 L1037 201 L1038 201 L1038 206 L1039 206 L1040 220 L1041 220 L1041 231 L1042 231 L1042 250 L1041 250 L1040 269 L1039 269 L1039 275 L1038 275 L1038 280 L1037 280 L1037 285 L1036 285 L1035 293 L1034 293 L1034 296 L1033 296 L1033 300 L1032 300 L1032 303 L1031 303 L1031 306 L1030 306 L1030 309 L1029 309 L1029 312 L1028 312 L1027 318 L1026 318 L1026 320 L1025 320 L1025 322 L1024 322 L1024 325 L1023 325 L1023 327 L1022 327 L1022 329 L1021 329 L1021 332 L1020 332 L1020 334 L1019 334 L1019 336 L1018 336 L1018 338 L1017 338 L1017 340 L1016 340 L1016 342 L1015 342 L1013 348 L1011 349 L1009 355 L1007 356 L1006 360 L1004 361 L1004 363 L1002 364 L1002 366 L1000 367 L1000 369 L997 371 L997 373 L995 374 L995 376 L994 376 L994 377 L992 378 L992 380 L987 384 L987 386 L981 391 L981 393 L980 393 L980 394 L979 394 L979 395 L978 395 L978 396 L977 396 L977 397 L976 397 L976 398 L975 398 L975 399 L974 399 L974 400 L973 400 L973 401 L972 401 L972 402 L971 402 L971 403 L970 403 L970 404 L969 404 L964 410 L962 410 L962 411 L961 411 L956 417 L954 417 L954 418 L953 418 L951 421 L949 421 L946 425 L944 425 L942 428 L940 428 L939 430 L937 430 L936 432 L934 432 L933 434 L931 434 L930 436 L928 436 L928 437 L925 438 L924 440 L922 440 L922 441 L916 443 L915 445 L913 445 L913 446 L911 446 L911 447 L909 447 L909 448 L907 448 L907 449 L905 449 L905 450 L903 450 L903 451 L901 451 L901 452 L898 452 L898 453 L896 453 L896 454 L894 454 L894 455 L892 455 L892 456 L886 457 L886 458 L884 458 L884 459 L881 459 L881 460 L878 460 L878 461 L875 461 L875 462 L871 462 L871 463 L868 463 L868 464 L864 464 L864 465 L859 465 L859 466 L854 466 L854 467 L848 467 L848 468 L839 468 L839 469 L713 469 L713 468 L705 468 L705 467 L699 467 L699 466 L694 466 L694 465 L689 465 L689 464 L685 464 L685 463 L677 462 L677 461 L674 461 L674 460 L671 460 L671 459 L665 458 L665 457 L663 457 L663 456 L660 456 L660 455 L658 455 L658 454 L655 454 L655 453 L653 453 L653 452 L651 452 L651 451 L648 451 L648 450 L646 450 L646 449 L644 449 L644 448 L638 446 L637 444 L631 442 L630 440 L626 439 L625 437 L623 437 L623 436 L621 436 L620 434 L618 434 L617 432 L615 432 L613 429 L611 429 L611 428 L608 427 L605 423 L603 423 L600 419 L598 419 L598 418 L597 418 L592 412 L590 412 L590 411 L589 411 L589 410 L588 410 L588 409 L587 409 L587 408 L586 408 L586 407 L585 407 L585 406 L584 406 L584 405 L583 405 L583 404 L582 404 L582 403 L581 403 L581 402 L580 402 L580 401 L574 396 L574 394 L573 394 L573 393 L569 390 L569 388 L564 384 L564 382 L561 380 L561 378 L559 377 L559 375 L556 373 L556 371 L554 370 L554 368 L553 368 L552 365 L550 364 L550 362 L549 362 L549 360 L547 359 L546 355 L544 354 L544 351 L545 351 L546 347 L548 346 L550 340 L552 339 L553 335 L555 334 L556 330 L558 329 L560 323 L562 322 L564 316 L566 315 L568 309 L569 309 L571 306 L756 306 L756 378 L758 378 L758 377 L760 377 L762 374 L764 374 L768 369 L770 369 L772 366 L774 366 L778 361 L780 361 L782 358 L784 358 L787 354 L789 354 L792 350 L794 350 L797 346 L799 346 L802 342 L804 342 L807 338 L809 338 L809 337 L810 337 L811 335 L813 335 L816 331 L818 331 L822 326 L824 326 L824 325 L825 325 L826 323 L828 323 L831 319 L833 319 L836 315 L838 315 L841 311 L843 311 L846 307 L848 307 L851 303 L853 303 L856 299 L858 299 L861 295 L863 295 L866 291 L868 291 L871 287 L873 287 L876 283 L878 283 L881 279 L883 279 L886 275 L888 275 L891 271 L893 271 L896 267 L898 267 L902 262 L904 262 L907 258 L909 258 L912 254 L914 254 L914 253 L915 253 L918 249 L920 249 L924 244 L926 244 L926 243 L928 242 L928 241 L927 241 L925 238 L923 238 L919 233 L917 233 L913 228 L911 228 L909 225 L907 225 L904 221 L902 221 L899 217 L897 217 L893 212 L891 212 L891 211 L890 211 L889 209 L887 209 L884 205 L882 205 L877 199 L875 199 L872 195 L870 195 L868 192 L866 192 L862 187 L860 187 L858 184 L856 184 L853 180 L851 180 L848 176 L846 176 L843 172 L841 172 L838 168 L836 168 L832 163 L830 163 L828 160 L826 160 L822 155 L820 155 L818 152 L816 152 L813 148 L811 148 L808 144 L806 144 L803 140 L801 140 L798 136 L796 136 L793 132 L791 132 L788 128 L786 128 L784 125 L782 125 L779 121 L777 121 L773 116 L771 116 L769 113 L767 113 L765 110 L763 110 L763 109 L762 109 L760 106 L758 106 L757 104 L756 104 L756 177 L570 177 L570 176 L568 175 L566 169 L564 168 L563 164 L561 163 L559 157 L557 156 L555 150 L553 149 L552 145 L550 144 L550 142 L549 142 L549 140 L548 140 L548 138 L546 137 L546 135 L545 135 L544 132 L545 132 L545 130 L547 129 L547 127 L548 127 L548 125 L550 124 L550 122 L551 122 L551 120 L553 119 L553 117 L555 116 L555 114 L556 114 L556 113 L558 112 L558 110 L560 109 L560 107 L563 105 L563 103 L566 101 L566 99 L571 95 L571 93 L572 93 L572 92 L573 92 L573 91 L574 91 L574 90 L580 85 L580 83 L581 83 L584 79 L586 79 L586 78 L587 78 L587 77 L588 77 L588 76 L589 76 L589 75 L590 75 L590 74 L591 74 L596 68 L598 68 L602 63 L604 63 L607 59 L609 59 L610 57 L612 57 L614 54 L616 54 L617 52 L619 52 L619 51 L622 50 L623 48 L627 47 L628 45 L632 44 L633 42 L635 42 L635 41 L637 41 L637 40 L639 40 L639 39 L641 39 L641 38 L643 38 L643 37 L645 37 L645 36 L647 36 L647 35 L649 35 L649 34 L651 34 L651 33 L653 33 L653 32 L656 32 L656 31 L658 31 L658 30 L661 30 L661 29 L663 29 L663 28 L666 28 L666 27 L669 27 L669 26 L672 26 L672 25 L675 25 L675 24 L678 24 L678 23 L682 23 L682 22 L686 22 L686 21 L690 21 L690 20 L695 20 L695 19 L700 19 L700 18 L715 17 Z"/>
    <path d="M17 520 L82 520 L82 521 L83 521 L83 539 L38 539 L38 540 L37 540 L37 551 L38 551 L38 554 L76 554 L76 570 L77 570 L76 572 L37 572 L37 587 L38 587 L38 588 L83 588 L83 606 L17 606 L17 605 L16 605 L16 604 L17 604 L17 565 L16 565 L16 563 L17 563 L17 562 L16 562 L16 560 L17 560 L17 529 L16 529 L16 528 L17 528 L17 522 L16 522 L16 521 L17 521 Z M128 520 L179 520 L179 521 L184 521 L184 522 L188 522 L188 523 L192 524 L193 526 L195 526 L195 527 L201 532 L201 534 L202 534 L203 537 L204 537 L204 540 L205 540 L205 546 L206 546 L206 557 L205 557 L205 562 L204 562 L204 564 L203 564 L201 570 L200 570 L195 576 L189 578 L189 581 L192 583 L192 585 L194 586 L194 588 L197 590 L197 592 L198 592 L198 593 L200 594 L200 596 L204 599 L204 601 L206 602 L206 604 L207 604 L208 606 L183 606 L183 604 L182 604 L181 601 L178 599 L178 597 L175 595 L175 593 L174 593 L173 590 L170 588 L170 586 L168 585 L167 582 L148 582 L148 606 L128 606 L128 601 L127 601 L127 599 L128 599 L128 589 L127 589 L127 583 L128 583 L128 569 L127 569 L127 547 L128 547 L128 541 L127 541 L127 529 L128 529 Z M248 520 L298 520 L298 521 L307 522 L307 523 L311 524 L312 526 L314 526 L314 527 L320 532 L320 534 L321 534 L322 537 L323 537 L324 544 L325 544 L325 558 L324 558 L324 562 L323 562 L323 565 L322 565 L321 569 L319 570 L319 572 L318 572 L316 575 L314 575 L313 577 L311 577 L311 578 L309 578 L309 579 L307 579 L307 580 L304 580 L304 581 L298 581 L298 582 L269 582 L269 606 L248 606 Z M436 520 L463 520 L463 521 L472 521 L472 522 L478 522 L478 523 L482 523 L482 524 L483 524 L483 530 L482 530 L482 542 L481 542 L481 543 L480 543 L480 542 L471 541 L471 540 L465 540 L465 539 L437 539 L437 540 L435 540 L435 541 L433 542 L433 545 L432 545 L433 550 L434 550 L436 553 L440 553 L440 554 L465 554 L465 555 L469 555 L469 556 L472 556 L472 557 L478 559 L478 560 L484 565 L484 567 L485 567 L485 569 L486 569 L486 573 L487 573 L487 586 L486 586 L486 590 L485 590 L483 596 L482 596 L478 601 L476 601 L475 603 L473 603 L473 604 L471 604 L471 605 L460 606 L460 607 L436 607 L436 606 L426 606 L426 605 L421 605 L421 604 L417 604 L417 603 L412 602 L413 582 L414 582 L414 583 L417 583 L417 584 L420 584 L420 585 L429 586 L429 587 L434 587 L434 588 L460 588 L460 587 L463 587 L463 586 L466 584 L466 577 L465 577 L465 575 L462 574 L462 573 L440 573 L440 572 L432 572 L432 571 L429 571 L429 570 L426 570 L426 569 L422 568 L420 565 L418 565 L418 564 L416 563 L416 561 L415 561 L414 558 L413 558 L413 555 L412 555 L412 539 L413 539 L413 536 L414 536 L415 532 L416 532 L417 529 L418 529 L420 526 L422 526 L423 524 L428 523 L428 522 L431 522 L431 521 L436 521 Z M513 520 L536 520 L536 521 L538 522 L538 524 L540 525 L541 529 L543 530 L544 534 L545 534 L546 537 L548 538 L550 544 L551 544 L552 547 L554 548 L556 554 L558 554 L558 552 L559 552 L560 549 L562 548 L563 544 L565 543 L565 541 L567 540 L568 536 L570 535 L570 533 L572 532 L573 528 L575 527 L576 523 L577 523 L579 520 L602 520 L602 522 L600 523 L600 525 L598 526 L598 528 L596 529 L596 531 L595 531 L594 534 L592 535 L591 539 L589 540 L589 542 L587 543 L587 545 L586 545 L585 548 L583 549 L582 553 L580 554 L580 556 L579 556 L578 559 L576 560 L575 564 L573 565 L573 567 L571 568 L571 570 L570 570 L569 573 L568 573 L568 606 L547 606 L547 574 L546 574 L546 572 L544 571 L544 569 L543 569 L543 567 L541 566 L541 564 L539 563 L539 561 L537 560 L537 558 L536 558 L536 556 L534 555 L534 553 L532 552 L532 550 L530 549 L530 547 L528 546 L527 542 L525 541 L525 539 L523 538 L523 536 L521 535 L521 533 L519 532 L519 530 L518 530 L517 527 L515 526 Z M654 520 L682 520 L682 521 L691 521 L691 522 L696 522 L696 523 L700 523 L700 524 L701 524 L701 528 L700 528 L700 542 L699 542 L699 543 L698 543 L698 542 L694 542 L694 541 L684 540 L684 539 L654 539 L654 540 L651 542 L651 544 L650 544 L650 548 L651 548 L651 550 L652 550 L653 552 L655 552 L655 553 L659 553 L659 554 L683 554 L683 555 L687 555 L687 556 L690 556 L690 557 L696 559 L696 560 L701 564 L701 566 L702 566 L702 568 L703 568 L703 570 L704 570 L704 575 L705 575 L705 585 L704 585 L704 589 L703 589 L703 592 L702 592 L702 594 L700 595 L700 597 L699 597 L696 601 L692 602 L691 604 L684 605 L684 606 L677 606 L677 607 L654 607 L654 606 L644 606 L644 605 L639 605 L639 604 L635 604 L635 603 L631 603 L631 602 L630 602 L631 582 L632 582 L632 583 L635 583 L635 584 L638 584 L638 585 L642 585 L642 586 L646 586 L646 587 L652 587 L652 588 L678 588 L678 587 L681 587 L681 586 L683 585 L683 583 L684 583 L684 578 L683 578 L683 576 L682 576 L681 574 L679 574 L679 573 L661 573 L661 572 L650 572 L650 571 L646 571 L646 570 L644 570 L644 569 L638 567 L638 566 L634 563 L634 561 L632 560 L632 558 L631 558 L631 556 L630 556 L629 542 L630 542 L630 538 L631 538 L632 533 L634 532 L634 530 L635 530 L639 525 L641 525 L641 524 L643 524 L643 523 L645 523 L645 522 L648 522 L648 521 L654 521 Z M735 520 L808 520 L808 524 L809 524 L809 530 L808 530 L808 537 L809 537 L809 538 L808 538 L808 539 L782 539 L782 606 L761 606 L761 539 L734 539 L734 521 L735 521 Z M842 520 L907 520 L907 521 L908 521 L908 522 L907 522 L907 530 L908 530 L907 539 L862 539 L862 554 L901 554 L901 572 L862 572 L862 588 L908 588 L908 606 L841 606 L841 521 L842 521 Z M948 520 L970 520 L970 521 L972 522 L972 524 L973 524 L975 530 L977 531 L979 537 L981 538 L982 542 L984 543 L984 545 L985 545 L985 547 L986 547 L988 553 L990 554 L990 556 L991 556 L991 558 L993 559 L993 561 L994 561 L995 564 L998 562 L999 558 L1001 557 L1002 553 L1004 552 L1006 546 L1008 545 L1010 539 L1012 538 L1012 536 L1013 536 L1014 532 L1016 531 L1018 525 L1020 524 L1021 520 L1045 520 L1045 606 L1024 606 L1024 556 L1023 556 L1022 559 L1020 560 L1019 564 L1017 565 L1016 569 L1014 570 L1012 576 L1010 577 L1010 579 L1009 579 L1009 581 L1007 582 L1007 584 L1006 584 L1006 586 L1005 586 L1005 588 L1004 588 L1003 591 L988 591 L986 585 L984 584 L982 578 L980 577 L978 571 L976 570 L974 564 L972 563 L970 557 L968 556 L968 606 L947 606 L947 595 L948 595 L948 591 L947 591 L947 550 L948 550 L947 529 L948 529 L948 522 L947 522 L947 521 L948 521 Z M149 539 L148 539 L148 563 L178 563 L178 562 L180 562 L180 561 L183 559 L183 556 L184 556 L184 546 L183 546 L183 544 L182 544 L179 540 L177 540 L177 539 Z M270 539 L269 539 L269 563 L293 564 L293 563 L297 563 L297 562 L299 562 L299 561 L303 558 L303 555 L304 555 L304 547 L303 547 L303 544 L302 544 L299 540 L297 540 L297 539 Z"/>
    </g>
    <clipPath id="{{ $goErpLogoId }}-fragment-0" clipPathUnits="userSpaceOnUse"><polygon points="530,311 0,0 265,0"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-1" clipPathUnits="userSpaceOnUse"><polygon points="530,311 265,0 530,0"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-2" clipPathUnits="userSpaceOnUse"><polygon points="530,311 530,0 795,0"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-3" clipPathUnits="userSpaceOnUse"><polygon points="530,311 795,0 1061,0"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-4" clipPathUnits="userSpaceOnUse"><polygon points="530,311 1061,0 1061,156"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-5" clipPathUnits="userSpaceOnUse"><polygon points="530,311 1061,156 1061,311"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-6" clipPathUnits="userSpaceOnUse"><polygon points="530,311 1061,311 1061,467"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-7" clipPathUnits="userSpaceOnUse"><polygon points="530,311 1061,467 1061,623"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-8" clipPathUnits="userSpaceOnUse"><polygon points="530,311 1061,623 795,623"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-9" clipPathUnits="userSpaceOnUse"><polygon points="530,311 795,623 530,623"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-10" clipPathUnits="userSpaceOnUse"><polygon points="530,311 530,623 265,623"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-11" clipPathUnits="userSpaceOnUse"><polygon points="530,311 265,623 0,623"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-12" clipPathUnits="userSpaceOnUse"><polygon points="530,311 0,623 0,467"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-13" clipPathUnits="userSpaceOnUse"><polygon points="530,311 0,467 0,311"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-14" clipPathUnits="userSpaceOnUse"><polygon points="530,311 0,311 0,156"/></clipPath>
    <clipPath id="{{ $goErpLogoId }}-fragment-15" clipPathUnits="userSpaceOnUse"><polygon points="530,311 0,156 0,0"/></clipPath>
  </defs>
  <g class="go-erp-logo-stage" transform="{{ $goErpSceneTransform }}">
  <g class="go-erp-login-flow go-erp-solar-system go-erp-solar-back" opacity="0" aria-hidden="true" transform="{{ $goErpSolarTransform }}">
    <g fill="#d7e4ee" opacity=".26">
      <circle cx="553.8" cy="-95.8" r="1.7"/>
      <circle cx="307.3" cy="-114.2" r="1.4"/>
      <circle cx="701.7" cy="-412.0" r="0.7"/>
      <circle cx="407.6" cy="-123.1" r="0.9"/>
      <circle cx="532.2" cy="-317.9" r="1.5"/>
      <circle cx="1002.9" cy="-279.7" r="1.7"/>
      <circle cx="66.0" cy="-93.1" r="1.6"/>
      <circle cx="160.1" cy="-141.7" r="1.2"/>
      <circle cx="1013.5" cy="-366.7" r="1.2"/>
      <circle cx="936.3" cy="-172.0" r="1.0"/>
      <circle cx="86.9" cy="-35.3" r="1.1"/>
      <circle cx="1057.1" cy="-397.7" r="1.5"/>
      <circle cx="863.0" cy="-44.3" r="0.7"/>
      <circle cx="394.9" cy="-438.5" r="1.4"/>
      <circle cx="376.3" cy="-26.4" r="1.0"/>
      <circle cx="821.6" cy="-68.0" r="0.9"/>
      <circle cx="590.8" cy="-27.3" r="1.2"/>
      <circle cx="381.6" cy="-340.7" r="1.5"/>
      <circle cx="204.5" cy="-187.8" r="1.2"/>
      <circle cx="587.2" cy="-403.2" r="1.3"/>
      <circle cx="972.4" cy="-317.2" r="1.0"/>
      <circle cx="964.5" cy="-199.4" r="0.9"/>
      <circle cx="10.4" cy="-120.0" r="1.0"/>
      <circle cx="495.7" cy="-338.3" r="1.3"/>
      <circle cx="773.5" cy="-321.4" r="0.7"/>
      <circle cx="1047.0" cy="-48.8" r="0.7"/>
      <circle cx="598.6" cy="-150.6" r="1.1"/>
      <circle cx="776.3" cy="-26.2" r="1.3"/>
      <circle cx="415.5" cy="-373.2" r="0.9"/>
      <circle cx="160.0" cy="-411.9" r="0.9"/>
      <circle cx="893.4" cy="-218.2" r="1.5"/>
      <circle cx="976.3" cy="-93.9" r="0.7"/>
    </g>
    <g fill="none" stroke="#456078" stroke-width="1.5" stroke-opacity=".42">
      <ellipse data-solar-track="mercury" cx="530" cy="-230" rx="90" ry="36.0"/>
      <ellipse data-solar-track="venus" cx="530" cy="-230" rx="145" ry="58.0"/>
      <ellipse data-solar-track="earth" cx="530" cy="-230" rx="205" ry="82.0"/>
      <ellipse data-solar-track="mars" cx="530" cy="-230" rx="265" ry="106.0"/>
      <ellipse data-solar-track="jupiter" cx="530" cy="-230" rx="335" ry="134.0"/>
      <ellipse data-solar-track="saturn" cx="530" cy="-230" rx="405" ry="162.0"/>
      <ellipse data-solar-track="uranus" cx="530" cy="-230" rx="475" ry="190.0"/>
      <ellipse data-solar-track="neptune" cx="530" cy="-230" rx="545" ry="218.0"/>
    </g>
    <g fill="#b8a18b" opacity=".35">
      <circle cx="793.4" cy="-282.9" r="1.5"/>
      <circle cx="692.0" cy="-333.6" r="1.1"/>
      <circle cx="792.5" cy="-176.5" r="1.3"/>
      <circle cx="545.8" cy="-108.9" r="1.0"/>
      <circle cx="684.6" cy="-127.8" r="1.4"/>
      <circle cx="230.6" cy="-220.1" r="1.2"/>
      <circle cx="244.5" cy="-270.3" r="1.2"/>
      <circle cx="830.9" cy="-255.0" r="1.0"/>
      <circle cx="406.7" cy="-122.1" r="1.1"/>
      <circle cx="832.3" cy="-208.2" r="1.3"/>
      <circle cx="255.9" cy="-172.1" r="1.1"/>
      <circle cx="256.4" cy="-288.9" r="1.3"/>
      <circle cx="302.5" cy="-308.2" r="1.6"/>
      <circle cx="539.0" cy="-349.5" r="1.1"/>
      <circle cx="275.8" cy="-295.9" r="1.2"/>
      <circle cx="638.2" cy="-116.4" r="1.4"/>
      <circle cx="558.4" cy="-350.1" r="1.2"/>
      <circle cx="226.9" cy="-233.2" r="1.2"/>
      <circle cx="827.1" cy="-220.9" r="1.6"/>
      <circle cx="669.3" cy="-337.1" r="1.4"/>
      <circle cx="601.9" cy="-114.3" r="1.2"/>
      <circle cx="438.1" cy="-341.5" r="1.2"/>
      <circle cx="783.1" cy="-297.8" r="1.6"/>
      <circle cx="782.5" cy="-157.9" r="1.5"/>
      <circle cx="829.1" cy="-210.2" r="1.4"/>
      <circle cx="583.3" cy="-109.7" r="1.5"/>
      <circle cx="612.1" cy="-345.9" r="0.8"/>
      <circle cx="242.4" cy="-258.9" r="1.3"/>
      <circle cx="493.5" cy="-111.1" r="1.3"/>
      <circle cx="661.9" cy="-342.9" r="1.1"/>
      <circle cx="836.9" cy="-224.2" r="1.1"/>
      <circle cx="544.8" cy="-353.4" r="0.8"/>
      <circle cx="494.1" cy="-348.5" r="1.1"/>
      <circle cx="376.9" cy="-125.3" r="1.0"/>
      <circle cx="231.8" cy="-225.9" r="0.9"/>
      <circle cx="530.0" cy="-107.6" r="1.1"/>
      <circle cx="622.1" cy="-115.8" r="1.0"/>
      <circle cx="836.5" cy="-242.0" r="0.9"/>
      <circle cx="448.1" cy="-114.0" r="1.2"/>
      <circle cx="298.5" cy="-151.8" r="1.4"/>
      <circle cx="702.0" cy="-129.9" r="0.9"/>
      <circle cx="238.1" cy="-255.9" r="1.5"/>
      <circle cx="758.2" cy="-314.6" r="1.1"/>
      <circle cx="310.0" cy="-309.3" r="1.4"/>
      <circle cx="582.7" cy="-348.6" r="1.1"/>
      <circle cx="545.6" cy="-352.7" r="1.5"/>
      <circle cx="458.4" cy="-114.5" r="1.0"/>
      <circle cx="636.2" cy="-120.7" r="0.9"/>
      <circle cx="229.1" cy="-224.5" r="1.6"/>
      <circle cx="316.3" cy="-147.9" r="1.6"/>
      <circle cx="798.7" cy="-177.1" r="1.4"/>
      <circle cx="395.1" cy="-117.8" r="1.4"/>
      <circle cx="419.2" cy="-114.5" r="1.6"/>
      <circle cx="235.4" cy="-240.4" r="0.9"/>
      <circle cx="658.3" cy="-342.8" r="1.4"/>
      <circle cx="576.0" cy="-351.8" r="1.0"/>
      <circle cx="600.9" cy="-109.5" r="1.5"/>
      <circle cx="255.5" cy="-183.4" r="0.9"/>
      <circle cx="274.0" cy="-159.9" r="1.0"/>
      <circle cx="411.9" cy="-122.4" r="1.3"/>
      <circle cx="814.3" cy="-184.9" r="1.2"/>
      <circle cx="266.1" cy="-174.7" r="1.1"/>
      <circle cx="773.3" cy="-307.4" r="1.4"/>
      <circle cx="598.5" cy="-114.4" r="1.1"/>
      <circle cx="751.0" cy="-317.5" r="1.0"/>
      <circle cx="329.2" cy="-319.3" r="1.2"/>
      <circle cx="629.1" cy="-113.9" r="1.5"/>
      <circle cx="810.6" cy="-274.9" r="1.2"/>
      <circle cx="350.8" cy="-324.0" r="1.5"/>
      <circle cx="563.5" cy="-348.2" r="1.1"/>
      <circle cx="222.0" cy="-219.2" r="1.4"/>
      <circle cx="551.2" cy="-105.9" r="1.6"/>
      <circle cx="719.5" cy="-324.2" r="1.2"/>
      <circle cx="270.6" cy="-160.9" r="1.2"/>
      <circle cx="817.0" cy="-259.8" r="0.8"/>
      <circle cx="237.8" cy="-209.3" r="1.0"/>
      <circle cx="817.8" cy="-203.5" r="1.2"/>
      <circle cx="784.6" cy="-167.6" r="1.3"/>
      <circle cx="511.3" cy="-110.7" r="1.1"/>
      <circle cx="504.7" cy="-108.9" r="1.5"/>
      <circle cx="827.4" cy="-206.4" r="1.3"/>
      <circle cx="796.8" cy="-290.2" r="1.0"/>
      <circle cx="298.8" cy="-312.7" r="1.6"/>
      <circle cx="562.4" cy="-347.3" r="1.1"/>
    </g>
  </g>
  <g class="go-erp-logo-rest" fill="currentColor" opacity="1">
    <use href="#{{ $goErpLogoId }}-shape"/>
  </g>
  <g class="go-erp-login-flow go-erp-solar-system go-erp-solar-front" opacity="0" aria-hidden="true" transform="{{ $goErpSolarTransform }}">
    <g data-solar-body="sun" transform="translate(530 -230)">
      <circle class="go-erp-solar-corona" r="108" fill="url(#{{ $goErpLogoId }}-sun-glow)"/>
      <circle r="43" fill="url(#{{ $goErpLogoId }}-sun)"/>
      <path d="M-28 -17 Q-8 -30 10 -18 M-20 15 Q0 24 22 10 M-7 -7 Q8 -16 23 -4" fill="none" stroke="#fff0aa" stroke-width="1.3" opacity=".36"/>
      <path d="M-34 -25 Q-48 -26 -41 -12 M32 27 Q44 33 42 16" fill="none" stroke="#ffa536" stroke-width="2.2" opacity=".65"/>
    </g>
    <g data-solar-planet="mercury" style="--orbit-start: -35deg; --orbit-end: 325deg; --counter-start: 35deg; --counter-end: -325deg; --orbit-period: 24s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(-35)">
          <g transform="translate(90 0)">
            <g class="go-erp-solar-counter" transform="rotate(35)">
              <g transform="scale(1 2.5)">
                <circle r="7" fill="url(#{{ $goErpLogoId }}-mercury)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-mercury-clip)"><g fill="#494541" opacity=".37"><circle cx="-2.8" cy="-2.4" r="1.6"/><circle cx="2.8" cy="1.8" r="1.2"/><circle cx="-1" cy="4" r=".7"/></g><path d="M-4 -1 Q-2 -3 0 -2" fill="none" stroke="#f1dfc8" stroke-width=".5" opacity=".45"/></g>
                <circle r="7" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="venus" style="--orbit-start: 132deg; --orbit-end: 492deg; --counter-start: -132deg; --counter-end: -492deg; --orbit-period: 34s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(132)">
          <g transform="translate(145 0)">
            <g class="go-erp-solar-counter" transform="rotate(-132)">
              <g transform="scale(1 2.5)">
                <circle r="11.5" fill="url(#{{ $goErpLogoId }}-venus)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-venus-clip)"><path d="M-14 -4 Q-3 -9 13 -3 M-13 2 Q0 -3 13 3 M-10 8 Q0 3 12 8" fill="none" stroke="#f9e4b8" stroke-width="2" opacity=".5"/></g>
                <circle r="11.5" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="earth" style="--orbit-start: 45deg; --orbit-end: 405deg; --counter-start: -45deg; --counter-end: -405deg; --orbit-period: 46s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(45)">
          <g transform="translate(205 0)">
            <g class="go-erp-solar-counter" transform="rotate(-45)">
              <g transform="scale(1 2.5)">
                <circle r="12.5" fill="url(#{{ $goErpLogoId }}-earth)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-earth-clip)"><path d="M-10 -7 L-5 -10 -1 -7 -2 -3 2 -1 0 4 -3 7 -4 3 -7 1 -5 -3 -10 -4 Z M4 -9 L9 -7 12 -1 7 0 4 -3 2 -4 Z M6 6 L11 4 12 8 7 10 5 8 Z" fill="#74ad68"/><path d="M-10 -2 Q-2 -8 7 -5 M-5 9 Q1 4 11 4" fill="none" stroke="#e8f7ff" stroke-width="1.3" opacity=".65"/><path d="M-6 -11 Q0 -14 6 -10 L1 -9 Z" fill="#e3f5ff"/></g>
                <circle r="12.5" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
                <circle cx="21" cy="-5" r="2.8" fill="#bfc3c9"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="mars" style="--orbit-start: 220deg; --orbit-end: 580deg; --counter-start: -220deg; --counter-end: -580deg; --orbit-period: 58s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(220)">
          <g transform="translate(265 0)">
            <g class="go-erp-solar-counter" transform="rotate(-220)">
              <g transform="scale(1 2.5)">
                <circle r="9" fill="url(#{{ $goErpLogoId }}-mars)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-mars-clip)"><path d="M-7 -2 Q-1 -6 3 -2 L6 1 2 4 -2 2 -5 4 Z" fill="#703b2b" opacity=".55"/><ellipse cy="-8" rx="3.5" ry="1.5" fill="#e9dcd0" opacity=".8"/></g>
                <circle r="9" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="jupiter" style="--orbit-start: -65deg; --orbit-end: 295deg; --counter-start: 65deg; --counter-end: -295deg; --orbit-period: 76s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(-65)">
          <g transform="translate(335 0)">
            <g class="go-erp-solar-counter" transform="rotate(65)">
              <g transform="scale(1 2.5)">
                <circle r="28" fill="url(#{{ $goErpLogoId }}-jupiter)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-jupiter-clip)"><path d="M-34 -17 Q-2 -12 34 -18 L34 -12 Q0 -7 -34 -11 Z" fill="#b67d54"/><path d="M-34 -6 Q-4 -1 34 -7 L34 -1 Q0 3 -34 0 Z" fill="#f3d5a9"/><path d="M-33 4 Q0 9 33 3 L33 10 Q0 15 -33 10 Z" fill="#a96643"/><path d="M-31 15 Q0 20 31 15 L31 20 Q0 26 -31 21 Z" fill="#e4c18c"/><path d="M-30 -23 Q0 -19 30 -24 M-30 1 Q0 6 30 0" fill="none" stroke="#7e523b" stroke-width="1.2" opacity=".45"/><ellipse cx="11" cy="8" rx="6" ry="3.3" fill="#b7684f"/><ellipse cx="10" cy="7.5" rx="3.9" ry="2" fill="#d38c68"/></g>
                <circle r="28" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="saturn" style="--orbit-start: 150deg; --orbit-end: 510deg; --counter-start: -150deg; --counter-end: -510deg; --orbit-period: 98s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(150)">
          <g transform="translate(405 0)">
            <g class="go-erp-solar-counter" transform="rotate(-150)">
              <g transform="scale(1 2.5)">
                <g transform="rotate(-22)" fill="none"><ellipse rx="46" ry="14" stroke="#bda87c" stroke-width="7" opacity=".75"/><ellipse rx="39" ry="11.8" stroke="#e3d4a7" stroke-width="2.6" opacity=".85"/></g>
                <circle r="23" fill="url(#{{ $goErpLogoId }}-saturn)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-saturn-clip)"><path d="M-27 -11 Q0 -7 27 -12 M-27 -3 Q0 1 27 -4 M-27 5 Q0 9 27 4 M-26 13 Q0 17 26 12" fill="none" stroke="#a58c5b" stroke-width="2.2" opacity=".35"/></g>
                <circle r="23" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
                <g transform="rotate(-22)" fill="none"><path d="M-46 0 A46 14 0 0 0 46 0" stroke="#d8c698" stroke-width="6"/><path d="M-39 0 A39 11.8 0 0 0 39 0" stroke="#f0dfb2" stroke-width="1.7"/></g>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="uranus" style="--orbit-start: 25deg; --orbit-end: 385deg; --counter-start: -25deg; --counter-end: -385deg; --orbit-period: 122s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(25)">
          <g transform="translate(475 0)">
            <g class="go-erp-solar-counter" transform="rotate(-25)">
              <g transform="scale(1 2.5)">
                <circle r="17" fill="url(#{{ $goErpLogoId }}-uranus)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-uranus-clip)"><path d="M-19 -5 Q0 -1 19 -5 M-19 4 Q0 8 19 4" fill="none" stroke="#d4f4e8" stroke-width="1.2" opacity=".21"/></g>
                <circle r="17" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
    <g data-solar-planet="neptune" style="--orbit-start: 210deg; --orbit-end: 570deg; --counter-start: -210deg; --counter-end: -570deg; --orbit-period: 146s;">
      <g transform="translate(530 -230) scale(1 .4)">
        <g class="go-erp-solar-rotor" transform="rotate(210)">
          <g transform="translate(545 0)">
            <g class="go-erp-solar-counter" transform="rotate(-210)">
              <g transform="scale(1 2.5)">
                <circle r="17" fill="url(#{{ $goErpLogoId }}-neptune)" stroke="{{ $goErpPlanetStroke }}" stroke-width="2"/>
                <g clip-path="url(#{{ $goErpLogoId }}-neptune-clip)"><path d="M-20 -6 Q0 -3 20 -7 M-20 5 Q0 8 20 3" fill="none" stroke="#9cb9ec" stroke-width="1.4" opacity=".32"/><ellipse cx="5" cy="4" rx="4" ry="1.8" fill="#1a3e83" opacity=".65"/></g>
                <circle r="17" fill="url(#{{ $goErpLogoId }}-planet-shade)"/>
              </g>
            </g>
          </g>
        </g>
      </g>
    </g>
  </g>
  <g class="go-erp-logo-fragments" fill="currentColor" aria-hidden="true">
    <g class="go-erp-logo-fragment" style="--go-x: -106px; --go-y: -68px; --go-r: 5deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-0)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -59px; --go-y: -112px; --go-r: -7deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-1)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 65px; --go-y: -123px; --go-r: 9deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-2)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 106px; --go-y: -68px; --go-r: -11deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-3)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 137px; --go-y: -49px; --go-r: 5deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-4)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 163px; --go-y: -19px; --go-r: -7deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-5)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 134px; --go-y: 16px; --go-r: 9deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-6)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 137px; --go-y: 49px; --go-r: -11deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-7)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 130px; --go-y: 83px; --go-r: 5deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-8)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: 53px; --go-y: 101px; --go-r: -7deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-9)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -59px; --go-y: 112px; --go-r: 9deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-10)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -130px; --go-y: 83px; --go-r: -11deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-11)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -123px; --go-y: 44px; --go-r: 5deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-12)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -148px; --go-y: 18px; --go-r: -7deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-13)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -163px; --go-y: -19px; --go-r: 9deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-14)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
    <g class="go-erp-logo-fragment" style="--go-x: -124px; --go-y: -44px; --go-r: -11deg;">
      <g clip-path="url(#{{ $goErpLogoId }}-fragment-15)"><use href="#{{ $goErpLogoId }}-shape"/></g>
    </g>
  </g>
  </g>
  <g class="go-erp-logo-compact" display="none" transform="{{ $goErpCompactTransform }}" fill="currentColor" aria-hidden="true">
    <use href="#{{ $goErpLogoId }}-shape"/>
  </g>
</svg>

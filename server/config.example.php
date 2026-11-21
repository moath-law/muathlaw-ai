<?php
// إعدادات موصل معاذ للمحاماة
return [
  'site_origin'   => 'https://muathlaw.com',
  'admin_token'   => 'CHANGE_ME_RANDOM_SECRET',          // يُستبدل عند النشر
  'index_file'    => __DIR__ . '/data/index.json',
  'batch_size'    => 60,                        // صفحات لكل تشغيل للفهرسة
  'section_patterns' => [
    'articles'    => ['/articles/'],
    'rulings'     => ['/regulations/rulings/'],
    'regulations' => ['/regulations/laws/'],
  ],
];

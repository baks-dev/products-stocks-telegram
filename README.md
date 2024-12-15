# BaksDev Product Stocks Telegram

[![Version](https://img.shields.io/badge/version-7.1.8-blue)](https://github.com/baks-dev/products-stocks-telegram/releases)
![php 8.3+](https://img.shields.io/badge/php-min%208.3-red.svg)

Модуль управления складским учетом продукции с помощью Telegram

## Установка

``` bash
$ composer require baks-dev/products-stocks-telegram
```

## Дополнительно

Установка конфигурации и файловых ресурсов:

``` bash
$ php bin/console baks:assets:install
```

Изменения в схеме базы данных с помощью миграции

``` bash
$ php bin/console doctrine:migrations:diff

$ php bin/console doctrine:migrations:migrate
```

## Тестирование

``` bash
$ php bin/phpunit --group=products-stocks-telegram
```

## Лицензия ![License](https://img.shields.io/badge/MIT-green)

The MIT License (MIT). Обратитесь к [Файлу лицензии](LICENSE.md) за дополнительной информацией.


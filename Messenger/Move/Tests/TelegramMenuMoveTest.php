<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Products\Stocks\Telegram\Messenger\Move\Tests;

use BaksDev\Auth\Telegram\Repository\AccountTelegramAdmin\AccountTelegramAdminInterface;
use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Products\Category\Type\Id\CategoryProductUid;
use BaksDev\Products\Category\Type\Section\Field\Id\CategoryProductSectionFieldUid;
use BaksDev\Products\Stocks\Telegram\Messenger\Move\TelegramMoveMenu;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Bot\Repository\UsersTableTelegramSettings\TelegramBotSettingsInterface;
use BaksDev\Telegram\Request\TelegramRequest;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Wildberries\Products\Entity\Barcode\Event\WbBarcodeEvent;
use BaksDev\Wildberries\Products\Entity\Barcode\WbBarcode;
use BaksDev\Wildberries\Products\UseCase\Barcode\NewEdit\Custom\WbBarcodeCustomDTO;
use BaksDev\Wildberries\Products\UseCase\Barcode\NewEdit\Property\WbBarcodePropertyDTO;
use BaksDev\Wildberries\Products\UseCase\Barcode\NewEdit\WbBarcodeDTO;
use BaksDev\Wildberries\Products\UseCase\Barcode\NewEdit\WbBarcodeHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group products-stocks-telegram
 * @group products-stocks-telegram-move
 */
#[When(env: 'test')]
final class TelegramMenuMoveTest extends KernelTestCase
{
    private static ?string $chat = null;

    private static ?string $secret = null;

    public static function setUpBeforeClass(): void
    {
        $container = self::getContainer();

        /** @var AccountTelegramAdminInterface $AccountTelegramAdminInterface */
        $AccountTelegramAdminInterface = $container->get(AccountTelegramAdminInterface::class);
        self::$chat = $AccountTelegramAdminInterface->find();

        /** @var TelegramBotSettingsInterface $TelegramBotSettingsInterface */
        $TelegramBotSettingsInterface = $container->get(TelegramBotSettingsInterface::class);
        self::$secret = $TelegramBotSettingsInterface->settings()->getSecret();

        //        /** @var TelegramSecurityInterface $TelegramSecurityInterface */
        //        $TelegramSecurityInterface = $container->get(TelegramSecurityInterface::class);

        // модератор
        //        $isGrantedProfile = $TelegramSecurityInterface->isGranted(
        //            '018dd60e-9d2d-786e-9851-e9f8043029ec',
        //            'ROLE_PRODUCT_STOCK_WAREHOUSE_SEND',
        //            '018d36b7-0d03-71a8-b1b0-e57b5c186ef9',
        //        );


        //
        //        $isGrantedProfile = $TelegramSecurityInterface->isExistGranted(
        //            '018d36b7-0d03-71a8-b1b0-e57b5c186ef9',
        //            'ROLE_PRODUCT_STOCK_WAREHOUSE_SEND',
        //            '018d36b7-0d03-71a8-b1b0-e57b5c186ef9',
        //        );
        //
        //
        //        dd($isGrantedProfile);


        // ФВДМИН
        //        $isGrantedProfile = $TelegramSecurityInterface->isGrantedProfile(
        //            '018d3075-6e7b-7b5e-95f6-923243b1fa3d',
        //            '018d3075-6e7b-7b5e-95f6-923243b1fa3d',
        //            'ROLE_PRODUCT_STOCK_WAREHOUSE_SEND'
        //        );

        //dd($isGrantedProfile);
    }

    public function testUseCase(): void
    {
        if(self::$chat)
        {
            self::assertNotNull(self::$chat);
            self::assertNotNull(self::$secret);

            $jsonData = '{
            "update_id":'.random_int(100000000, 999999999).', 
            "message": {
            "message_id":'.random_int(1000, 9999).',
            "from":{"id":'.self::$chat.',
            "is_bot":false,
            "first_name":"First Name",
            "language_code":"ru"},
            "chat":{ "id":'.self::$chat.',
            "first_name":"First Name","type":"private"},
            "date":'.time().',
            "text":"/menu",
            "entities":[{"offset":0,"length":6,"type":"bot_command"}]
            }
        }';

            // Создаем объект Request с данными JSON
            $Request = Request::create(
                '/telegram/endpoint', // URL для запроса
                'POST',
                content: $jsonData // Данные в формате JSON
            );

            $Request->headers->set('Content-Type', 'application/json');
            $Request->headers->set('X-Telegram-Bot-Api-Secret-Token', self::$secret);

            /** @var TelegramRequest $TelegramRequest */
            $TelegramRequest = self::getContainer()->get(TelegramRequest::class);
            $TelegramRequest = $TelegramRequest->request($Request);

            $TelegramMenuMove = self::getContainer()->get(TelegramMoveMenu::class);

            ($TelegramMenuMove)(new TelegramEndpointMessage($TelegramRequest));
        }

        self::assertTrue(true);
    }
}
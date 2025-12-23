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
use BaksDev\Products\Stocks\Telegram\Messenger\Move\TelegramMoveProfile;
use BaksDev\Telegram\Api\TelegramSendMessages;
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
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;

#[When(env: 'test')]
#[Group('products-stocks-telegram')]
final class TelegramProfileMoveTest extends KernelTestCase
{
    private static ?string $chat;

    private static ?string $secret;

    public static function setUpBeforeClass(): void
    {
        $container = self::getContainer();

        /** @var AccountTelegramAdminInterface $AccountTelegramAdminInterface */
        $AccountTelegramAdminInterface = $container->get(AccountTelegramAdminInterface::class);
        self::$chat = $AccountTelegramAdminInterface->find();

        /** @var TelegramBotSettingsInterface $TelegramBotSettingsInterface */
        $TelegramBotSettingsInterface = $container->get(TelegramBotSettingsInterface::class);
        self::$secret = $TelegramBotSettingsInterface->settings()->getSecret();

    }

    public function testUseCase(): void
    {
        if(self::$chat)
        {
            self::assertNotNull(self::$secret);

            $jsonData = '{
        "update_id":'.random_int(100000000, 999999999).', 
        "callback_query":
        {
            "id":"5978273658621474505",
            
            "from":
            {
                "id":'.self::$chat.',
                "is_bot":false,
                "first_name":"First Name",
                "language_code":"ru"
            },
            
            "message":
            {
                "message_id":'.random_int(1000, 9999).',
                "from":{
                    "id":6571592607,
                    "is_bot":true,
                    "first_name":"BundlesBakDevTestBot",
                    "username":"BundlesBakDevTestBot"
                },
            
                "chat":{
                    "id":'.self::$chat.',
                    "first_name":"First Name",
                    "type":"private"
                },
                
                "date":'.time().',
                "text":"Выберите профиль пользователя:",
                "entities":[{"offset":17,"length":11,"type":"underline"}, {"offset":92,"length":5,"type":"bold"}],
                
                "reply_markup":
                {
                    "inline_keyboard":
                    [[
                        {"text":"Удалить","callback_data":"telegram-delete-message"},
                        {"text":"Начало сборки", "callback_data":"'.TelegramMoveProfile::KEY.'"}
                    ]]
                }
            },
        
            "chat_instance":"1167117868775207046",
            "data":"'.TelegramMoveProfile::KEY.'"
       
        }}';

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

            $TelegramMenuMove = self::getContainer()->get(TelegramMoveProfile::class);

            ($TelegramMenuMove)(new TelegramEndpointMessage($TelegramRequest));
        }

        self::assertTrue(true);

    }

}
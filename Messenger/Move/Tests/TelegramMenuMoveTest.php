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

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Products\Category\Type\Id\ProductCategoryUid;
use BaksDev\Products\Category\Type\Section\Field\Id\ProductCategorySectionFieldUid;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
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
 * @group products-stocks-telegram-
 */
#[When(env: 'test')]
final class TelegramMenuMoveTest extends KernelTestCase
{
    private static string $chat;

    public static function setUpBeforeClass(): void
    {
        self::$chat = $_SERVER['TEST_TELEGRAM_CHAT'];
    }

    public function testUseCase(): void
    {
        /** @var TelegramSendMessage $TelegramSendMessage */
        $TelegramSendMessage = self::getContainer()->get(TelegramSendMessage::class);

        /** @var MessageDispatchInterface $MessageDispatch */
        $MessageDispatch = self::getContainer()->get(MessageDispatchInterface::class);

        $jsonData = '{"update_id":844603512, "message":{"message_id":3356,"from":{"id":1391925303,"is_bot":false,"first_name":"Michel Angelo","language_code":"ru"},"chat":{"id":1391925303,"first_name":"Michel Angelo","type":"private"},"date":1710757581,"text":"/start","entities":[{"offset":0,"length":6,"type":"bot_command"}]}}';

        // Создаем объект Request с данными JSON
        $Request = Request::create(
            '/telegram/endpoint', // URL для запроса
            'POST', // Метод запроса
            [], // Параметры запроса
            [], // Cookies
            [], // Files
            [], // Server
            $jsonData // Данные в формате JSON
        );

        $Request->headers->set('Content-Type', 'application/json');
        $Request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'F7NC77RVR8he4H5Z');

        //dd($Request);

        /** @var TelegramRequest $TelegramRequest */
        $TelegramRequest = self::getContainer()->get(TelegramRequest::class);
        $TelegramRequest = $TelegramRequest->request($Request);

        $MessageDispatch->dispatch(
            new TelegramEndpointMessage($TelegramRequest),
            transport: 'telegram-bot'
        );

        //$MessageDispatchInterface->dispatch();


        //'{"update_id":844603512, "message":{"message_id":3356,"from":{"id":1391925303,"is_bot":false,"first_name":"Michel Angelo","language_code":"ru"},"chat":{"id":1391925303,"first_name":"Michel Angelo","type":"private"},"date":1710757581,"text":"/start","entities":[{"offset":0,"length":6,"type":"bot_command"}]}}';


        //$MessageDispatchInterface->dispatch()


        //        $response = $TelegramSendMessage
        //            ->chanel(self::$chat)
        //            ->message('213212')
        //            ->send()
        //        ;

        //тихо

        self::assertTrue(true);

    }

}
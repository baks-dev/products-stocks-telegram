<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Products\Stocks\Telegram\Messenger\Move;

use App\Kernel;
use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Core\Doctrine\ORMQueryBuilder;
use BaksDev\Products\Stocks\Entity\Stock\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\Stock\ProductStock;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusMoving;
use BaksDev\Products\Stocks\UseCase\Admin\Warehouse\WarehouseProductStockDTO;
use BaksDev\Products\Stocks\UseCase\Admin\Warehouse\WarehouseProductStockHandler;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Type\Id\UserUid;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TelegramMoveDone
{
    public const string KEY = 'pVPMFRhw';

    private ?UserProfileUid $profile;

    public function __construct(
        #[Target('productsStocksTelegramLogger')] private readonly LoggerInterface $logger,
        private readonly ORMQueryBuilder $ORMQueryBuilder,
        private readonly TelegramSendMessages $telegramSendMessage,
        private readonly TelegramMoveProcess $moveProcess,
        private readonly ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private readonly WarehouseProductStockHandler $warehouseProductStockHandler,
        private readonly TelegramSecurityInterface $telegramSecurity,
        private readonly ProductStockFixedInterface $productStockFixed,
    ) {}

    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestCallback $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(!($TelegramRequest instanceof TelegramRequestCallback))
        {
            return;
        }

        if($TelegramRequest->getCall() !== self::KEY)
        {
            return;
        }

        if(empty($TelegramRequest->getIdentifier()))
        {
            return;
        }

        $this->profile = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());

        if($this->profile === null)
        {
            return;
        }

        $this->handle($TelegramRequest);
        $message->complete();
    }

    /**
     * Заявка выполнена
     */
    public function handle(TelegramRequestCallback $TelegramRequest): void
    {

        /**
         * Получаем событие
         */
        $ProductStockEventUid = new ProductStockEventUid($TelegramRequest->getIdentifier());
        $ProductStockEvent = $this->getProductStockEvent($ProductStockEventUid);

        if(!$ProductStockEvent)
        {
            return;
        }

        $UserProfileUid = $ProductStockEvent->getInvariable()?->getProfile();

        if(!$this->telegramSecurity->isGranted($this->profile, 'ROLE_PRODUCT_STOCK_WAREHOUSE_SEND', $UserProfileUid))
        {

            $menu[] = [
                'text' => '❌',
                'callback_data' => 'telegram-delete-message'
            ];

            $menu[] = [
                'text' => 'Меню',
                'callback_data' => 'menu'
            ];

            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 2),
            ], JSON_THROW_ON_ERROR);

            $msg = '⛔️ Недостаточно прав для выполнения заявки перемещения продукции между складами';

            $this
                ->telegramSendMessage
                ->chanel($TelegramRequest->getChatId())
                ->delete([$TelegramRequest->getId(), $TelegramRequest->getLast()])
                ->message($msg)
                ->markup($markup)
                ->send();

            /** Снимаем фиксацию с заявки */

            $this->productStockFixed->cancel($ProductStockEventUid, $this->profile);

            return;
        }


        /**
         * Делаем отметку о комплектации
         */

        if(!Kernel::isTestEnvironment())
        {
            // UserUid необходим только для формы выбора профилей из списка
            $WarehouseProductStockDTO = new WarehouseProductStockDTO(new UserUid());

            $ProductStockEvent->getDto($WarehouseProductStockDTO);

            /** Перелинкуем профили */
            $WarehouseProductStockDTO->setProfile($ProductStockEvent->getMoveDestination());
            $WarehouseProductStockDTO->getMove()?->setDestination($ProductStockEvent->getInvariable()->getProfile());

            $ProductStock = $this->warehouseProductStockHandler->handle($WarehouseProductStockDTO);

            if(!$ProductStock instanceof ProductStock)
            {
                $msg = sprintf('%s: Возникла ошибка при сборке перемещения', $ProductStock);

                $this
                    ->telegramSendMessage
                    ->chanel($TelegramRequest->getChatId())
                    ->delete([$TelegramRequest->getId()])
                    ->message($msg)
                    ->send();

                return;
            }
        }

        $msg = '<b>Заявка на перемещение укомплектована:</b>';
        $msg .= PHP_EOL;

        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockEvent->getNumber());
        $msg .= PHP_EOL;

        $currentDate = new DateTimeImmutable();
        $msg .= sprintf('Дата: <b>%s</b>', $currentDate->format('d.m.Y H:i'));


        //        $msg .= PHP_EOL;
        //        $msg .= sprintf('DEBUG : <b>%s</b>', $ProductStockEvent->getProfile()) ;
        //
        //        $msg .= PHP_EOL;
        //        $msg .= sprintf('DEBUG : <b>%s</b>', $ProductStockEvent->getMoveDestination()) ;


        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete([$TelegramRequest->getId(), $TelegramRequest->getLast()])
            ->message($msg)
            ->send();


        /**
         * Получаем идентификатор профиля заявки и Отправляем следующую заявку на сборку
         */
        $profile = $ProductStockEvent->getMoveDestination();
        $TelegramRequest->setIdentifier((string) $profile);
        $this->moveProcess->handle($TelegramRequest);

        $this->logger->debug('Пользователь укомплектовал перемещение', [
            'number' => $ProductStockEvent->getNumber(),
            'ProductStockEventUid' => (string) $ProductStockEvent->getId(),
            'Current UserProfileUid' => (string) $this->profile
        ]);
    }


    private function getProductStockEvent(ProductStockEventUid $event): ?ProductStockEvent
    {
        $ProductStockEventUid = new ProductStockEventUid($event);

        $orm = $this->ORMQueryBuilder->createQueryBuilder(self::class);

        /** @var ProductStockEvent $ProductStockEvent */
        $orm
            ->select('event')
            ->from(ProductStockEvent::class, 'event')
            ->where('event.id = :event')
            ->setParameter('event', $ProductStockEventUid, ProductStockEventUid::TYPE)
            ->andWhere('event.status = :status')
            ->setParameter('status', new ProductStockStatus(ProductStockStatusMoving::class), ProductStockStatus::TYPE)
            ->join(
                ProductStock::class,
                'stock',
                'WITH',
                'stock.event = event.id'
            );

        return $orm->getOneOrNullResult();
    }
}


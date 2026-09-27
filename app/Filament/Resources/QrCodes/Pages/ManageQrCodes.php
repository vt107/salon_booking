<?php

namespace App\Filament\Resources\QrCodes\Pages;

use App\Filament\Resources\QrCodes\QrCodeResource;
use App\Support\AppUrl;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ManageQrCodes extends ManageRecords
{
    protected static string $resource = QrCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tạo mã QR'),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (AppUrl::isPublic()) {
            return null;
        }

        return new HtmlString('<span style="color: rgb(217 119 6)">⚠️ APP_URL đang là <b>'.e(config('app.url')).'</b>: mã QR tải về lúc này chỉ mở được trên máy này. Đặt APP_URL là tên miền thật trước khi in.</span>');
    }
}

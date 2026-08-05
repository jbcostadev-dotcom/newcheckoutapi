<?php

namespace Tests\Unit;

use App\Models\MetaPixelSetting;
use Tests\TestCase;

class MetaPixelSettingTest extends TestCase
{
    public function test_invalid_encrypted_token_disables_capi_without_breaking_checkout(): void
    {
        $setting = new MetaPixelSetting();
        $setting->setRawAttributes([
            'enabled' => true,
            'capi_enabled' => true,
            'pixel_id' => '123456789',
            'access_token' => 'value-encrypted-with-a-different-app-key',
        ]);

        $this->assertFalse($setting->isCapiActive());
    }
}

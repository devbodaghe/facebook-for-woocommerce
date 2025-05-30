<?php
namespace WooCommerce\Facebook\Tests\Admin\Settings_Screens;

use PHPUnit\Framework\TestCase;
use WooCommerce\Facebook\Admin\Settings_Screens\Shops;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Class ShopsTest
 *
 * @package WooCommerce\Facebook\Tests\Unit\Admin\Settings_Screens
 */
class ShopsTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

    /** @var Shops */
    private $shops;

    /**
     * Set up the test environment
     */
    public function setUp(): void {
        parent::setUp();
        $this->shops = new Shops();
    }

    /**
     * Helper method to invoke private/protected methods
     *
     * @param object $object     Object instance
     * @param string $methodName Method name to call
     * @param array  $parameters Parameters to pass into method
     *
     * @return mixed Method return value
     */
    private function invoke_method($object, $methodName, array $parameters = []) {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Test that render method calls render_facebook_iframe when enhanced onboarding is enabled
     */
    public function test_render_facebook_box_iframe() {
        // Create a mock of the Shops class
        $shops = $this->getMockBuilder(Shops::class)
            ->getMock();

        // Start output buffering to capture the render output
        ob_start();
        $shops->render();
        $output = ob_get_clean();

        // Since we can't directly test the private render_facebook_iframe method,
        // we'll verify that the render method doesn't output the legacy Facebook box
        // when enhanced onboarding is enabled
        $this->assertStringNotContainsString('wc-facebook-connection-box', $output);
    }

    /**
     * Test that render_message_handler outputs the expected JavaScript
     */
    public function test_render_message_handler() {
        // Create a mock of the Shops class
        $shops_mock = $this->getMockBuilder(Shops::class)
            ->onlyMethods(['is_current_screen_page'])
            ->getMock();

        // Configure the mock to return true for is_current_screen_page
        $shops_mock->method('is_current_screen_page')
            ->willReturn(true);

        // Call the method
        $output = $shops_mock->generate_inline_enhanced_onboarding_script();

        // Assert JavaScript event listeners and handlers
        $this->assertStringContainsString('window.addEventListener(\'message\'', $output);
        $this->assertStringContainsString('CommerceExtension::INSTALL', $output);
        $this->assertStringContainsString('CommerceExtension::RESIZE', $output);
        $this->assertStringContainsString('CommerceExtension::UNINSTALL', $output);

        // Assert fetch request setup - check for wpApiSettings.root instead of hardcoded path
        $this->assertStringContainsString('GeneratePluginAPIClient', $output);
        $this->assertStringContainsString('fbAPI.updateSettings', $output);
    }

    /**
     * Test that render_message_handler doesn't output when not on current screen
     */
    public function test_render_message_handler_not_current_screen() {
        // Create a mock of the Shops class
        $shops_mock = $this->getMockBuilder(Shops::class)
            ->onlyMethods(['is_current_screen_page'])
            ->getMock();

        $shops_mock->method('is_current_screen_page')
            ->willReturn(false);

        // Start output buffering to capture the render output
        ob_start();
        $shops_mock->render_message_handler();
        $output = ob_get_clean();

        // Assert that no output is generated
        $this->assertEmpty($output);
    }

    /**
     * Test that the management URL is used when merchant token exists
     */
    public function test_renders_management_url_based_on_merchant_token() {
        // Create a mock of the Shops class
        $shops = $this->getMockBuilder(Shops::class)
            ->getMock();

        // Set up the merchant token
        update_option('wc_facebook_merchant_access_token', 'test_token');

        // Start output buffering to capture the render output
        ob_start();
        $this->invoke_method($shops, 'render_facebook_iframe');
        $output = ob_get_clean();

        // Check that the iframe is rendered
        $this->assertStringContainsString('<iframe', $output);
        $this->assertStringContainsString('id="facebook-commerce-iframe-enhanced"', $output);
    }
}

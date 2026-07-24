<?php

namespace Tests\Unit\Services\Agent;

use App\Enums\FormFieldType;
use App\Services\Agent\FormHtmlParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FormHtmlParserTest extends TestCase
{
    private FormHtmlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new FormHtmlParser;
    }

    public function test_parses_plain_text_input(): void
    {
        $fields = $this->parser->parse('<input type="text" name="subject">');

        $this->assertCount(1, $fields);
        $this->assertSame('subject', $fields[0]['name']);
        $this->assertSame('text', $fields[0]['type']);
        $this->assertSame('Subject', $fields[0]['label']);
        $this->assertFalse($fields[0]['required']);
    }

    public function test_parses_email_input_with_placeholder(): void
    {
        $fields = $this->parser->parse(
            '<label for="email">Email address</label><input id="email" type="email" name="email" placeholder="you@example.com" required>'
        );

        $this->assertCount(1, $fields);
        $this->assertSame('email', $fields[0]['name']);
        $this->assertSame('email', $fields[0]['type']);
        $this->assertSame('Email address', $fields[0]['label']);
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('you@example.com', $fields[0]['placeholder']);
    }

    public function test_parses_textarea(): void
    {
        $fields = $this->parser->parse('<textarea name="message" required></textarea>');

        $this->assertCount(1, $fields);
        $this->assertSame('message', $fields[0]['name']);
        $this->assertSame('textarea', $fields[0]['type']);
        $this->assertTrue($fields[0]['required']);
    }

    public function test_parses_select_with_options(): void
    {
        $html = <<<'HTML'
<select name="topic" required>
    <option value="">Choose</option>
    <option>Sales</option>
    <option>Support</option>
</select>
HTML;

        $fields = $this->parser->parse($html);

        $this->assertCount(1, $fields);
        $this->assertSame('topic', $fields[0]['name']);
        $this->assertSame('select', $fields[0]['type']);
        $this->assertSame(['Choose', 'Sales', 'Support'], $fields[0]['options']);
    }

    public function test_skips_honeypot_fields_inside_offscreen_container(): void
    {
        $html = <<<'HTML'
<form>
    <input type="email" name="email" required>
    <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1"></label>
    </div>
</form>
HTML;

        $fields = $this->parser->parse($html);

        $this->assertCount(1, $fields);
        $this->assertSame('email', $fields[0]['name']);
    }

    public function test_skips_control_fields_prefixed_with_underscore(): void
    {
        $fields = $this->parser->parse(
            '<input type="email" name="email"><input type="hidden" name="_user_api" value="x"><input type="hidden" name="_timestamp" value="1">'
        );

        $this->assertCount(1, $fields);
        $this->assertSame('email', $fields[0]['name']);
    }

    public function test_skips_turnstile_response_field(): void
    {
        $fields = $this->parser->parse(
            '<input type="email" name="email"><input type="hidden" name="cf-turnstile-response" value="x">'
        );

        $this->assertCount(1, $fields);
        $this->assertSame('email', $fields[0]['name']);
    }

    public function test_empty_snippet_throws_runtime_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no usable form fields');

        $this->parser->parse('<p>Just text.</p>');
    }

    public function test_duplicate_names_collapse_to_first(): void
    {
        $html = <<<'HTML'
<input type="radio" name="topic" value="a">
<input type="radio" name="topic" value="b">
<input type="radio" name="topic" value="c">
HTML;

        $fields = $this->parser->parse($html);

        $this->assertCount(1, $fields);
        $this->assertSame('topic', $fields[0]['name']);
    }

    public function test_aria_required_marks_field_required(): void
    {
        $fields = $this->parser->parse('<input type="text" name="subject" aria-required="true">');

        $this->assertCount(1, $fields);
        $this->assertTrue($fields[0]['required']);
    }

    public function test_label_picked_up_from_preceding_label_with_for(): void
    {
        $fields = $this->parser->parse(
            '<label for="phone_number">Phone number *</label><input id="phone_number" name="phone_number" type="tel">'
        );

        $this->assertCount(1, $fields);
        $this->assertSame('Phone number *', $fields[0]['label']);
        $this->assertSame(FormFieldType::Tel, FormFieldType::from($fields[0]['type']));
    }

    public function test_field_position_is_in_document_order(): void
    {
        $html = '<input name="c" type="text"><input name="a" type="text"><input name="b" type="text">';

        $fields = $this->parser->parse($html);

        $this->assertCount(3, $fields);
        $this->assertSame(['c', 'a', 'b'], array_column($fields, 'name'));
        $this->assertSame([0, 1, 2], array_column($fields, 'position'));
    }

    public function test_help_text_picked_up_from_aria_describedby(): void
    {
        $html = <<<'HTML'
<input type="text" name="email" aria-describedby="email-help">
<small id="email-help">We'll never share this.</small>
HTML;

        $fields = $this->parser->parse($html);

        $this->assertCount(1, $fields);
        $this->assertSame("We'll never share this.", $fields[0]['help_text']);
    }

    public function test_handles_malformed_html_gracefully(): void
    {
        $fields = $this->parser->parse(
            '<form><input type="email" name="email"><span><p>oops</form>'
        );

        $this->assertCount(1, $fields);
        $this->assertSame('email', $fields[0]['name']);
    }
}

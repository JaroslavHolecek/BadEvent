<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    /** View::e escapuje HTML znaky i uvozovky (ochrana proti XSS). */
    public function testEscapesHtmlAndQuotes(): void
    {
        $this->assertSame('&lt;script&gt;&quot;x&quot; &amp; &#039;y&#039;', View::e('<script>"x" & \'y\''));
    }

    /** View::e zvládne i čísla a null. */
    public function testEscapesNonStrings(): void
    {
        $this->assertSame('42', View::e(42));
        $this->assertSame('', View::e(null));
    }

    /** permLink vykreslí odkaz jen s oprávněním, jinak neklikatelný šedý span. */
    public function testPermLinkRendersDisabledSpanWhenNotAllowed(): void
    {
        $this->assertSame('<a href="/actions/create" class="btn">Nová</a>', permLink(true, '/actions/create', 'Nová'));
        $this->assertStringStartsWith('<span class="btn btn-disabled"', permLink(false, '/actions/create', 'Nová'));
    }
}

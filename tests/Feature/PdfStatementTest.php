<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\MoneyImportService;
use App\Services\PushService;
use App\Support\PdfDecryptor;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * Relevés PDF de banques différentes : protégés (chiffrés), ré-imprimés, mises en page
 * variées. Les PDF de tests/Fixtures/pdf sont des relevés fictifs, protégés avec qpdf.
 */
class PdfStatementTest extends TestCase
{
    use RefreshDatabase;

    /** Les opérations du relevé fictif des fichiers protégés. */
    private const EXPECTED = [
        ['2026-10-02', 'CB CARREFOUR MARKET 01/10', -4520],
        ['2026-10-03', 'VIR SEPA RECU /DE DURAND PAUL', 120000],
        ['2026-10-07', 'CB POINT P 06/10', -123456],
        ['2026-10-12', 'CB TOTAL ENERGIES', -6215],
    ];

    private MoneyAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00');
        $this->app->instance(PushService::class, Mockery::spy(PushService::class));
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '2 000'])->assertSessionHasNoErrors();
        $this->account = MoneyAccount::query()->where('scope', 'perso')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: int}> */
    private function read(string $pdf, string $password = ''): array
    {
        return array_map(fn ($row) => [$row['date'], $row['label'], $row['amount']], app(MoneyImportService::class)->parse($pdf, $password));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/pdf/'.$name));
    }

    private function html(string $body): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body style="font-family: DejaVu Sans; font-size: 8.5pt">'.$body.'</body></html>');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param list<string> $head @param list<list<string>> $rows @param array<int, string> $align */
    private function table(array $head, array $rows, array $align = []): string
    {
        $html = '<table style="width:100%"><tr>';
        foreach ($head as $i => $cell) {
            $html .= '<th align="'.($align[$i] ?? 'left').'">'.$cell.'</th>';
        }
        foreach ($rows as $row) {
            $html .= '</tr><tr>';
            foreach ($row as $i => $cell) {
                $html .= '<td valign="top" align="'.($align[$i] ?? 'left').'">'.$cell.'</td>';
            }
        }

        return $html.'</tr></table>';
    }

    /** PDF écrit à la main (police de base) : contenu de la page, et éventuellement un bloc affiché par la page. */
    private function raw(string $content, ?string $form = null): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >>'.($form ? ' /XObject << /Fm1 6 0 R >>' : '').' >> >>',
            4 => '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        if ($form) {
            $objects[6] = '<< /Type /XObject /Subtype /Form /BBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Length '.strlen($form)." >>\nstream\n".$form."\nendstream";
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($out);
            $out .= $number." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** Lignes [y, [[x, texte]…]] en opérateurs PDF ; lettre par lettre si $glyphs. */
    private function textOps(array $lines, bool $glyphs): string
    {
        $ops = '';
        foreach ($lines as [$y, $cells]) {
            foreach ($cells as [$x, $text]) {
                $parts = $glyphs ? str_split($text) : [$text];
                foreach ($parts as $i => $part) {
                    if (trim($part) !== '') {
                        $ops .= sprintf("BT /F1 9 Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $x + ($glyphs ? $i * 5 : 0), $y, $part);
                    }
                }
            }
        }

        return $ops;
    }

    public function test_statements_protected_by_the_bank_are_read(): void
    {
        foreach (['protege-rc4-40.pdf', 'protege-rc4-128.pdf', 'protege-aes-128.pdf', 'protege-aes-256-objets.pdf', 'reimprime-aes-256.pdf'] as $file) {
            $pdf = $this->fixture($file);
            $this->assertTrue(PdfDecryptor::isEncrypted($pdf), $file);
            $this->assertSame(self::EXPECTED, $this->read($pdf), $file);
        }
        $this->assertSame('AES 256 bits', PdfDecryptor::describe($this->fixture('protege-aes-256-objets.pdf')));
        $this->assertSame('RC4 40 bits', PdfDecryptor::describe($this->fixture('protege-rc4-40.pdf')));

        // Par l'import, comme sur le téléphone.
        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [
            UploadedFile::fake()->createWithContent('releve-octobre.pdf', $this->fixture('protege-aes-128.pdf')),
        ]])->assertRedirect(route('import.show'));
        $this->get(route('import.show'))->assertOk()->assertSee('CB POINT P 06/10')->assertSee('Chantier');
    }

    public function test_a_statement_opened_with_a_password(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('releve.pdf', $this->fixture('mot-de-passe.pdf'));

        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [$file()]])
            ->assertSessionHasErrors(['file' => 'Ce relevé demande un mot de passe pour s\'ouvrir : tapez-le dans « Le PDF demande un mot de passe ? » (il ne sert qu\'à le lire, il n\'est pas gardé).'])
            ->assertSessionHas('import_issue', 'password');
        $this->get(route('import.create'))->assertSee('Le PDF demande un mot de passe ?');

        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'pdf_password' => 'faux', 'files' => [$file()]])
            ->assertSessionHasErrors('file')
            ->assertSessionMissing('_old_input.pdf_password');

        $this->post(route('import.preview'), ['account_id' => $this->account->id, 'pdf_password' => 'secret', 'files' => [$file()]])
            ->assertRedirect(route('import.show'));
        $this->assertSame(array_column(self::EXPECTED, 1), array_column($this->get(route('import.show'))->viewData('rows'), 'label'));
    }

    public function test_layouts_of_different_banks(): void
    {
        // Date « 03.10 », date de valeur après le libellé, libellés sur 2 lignes, colonnes Débit / Crédit.
        $bnp = $this->html('<p>RELEVE DE COMPTE - du 1 octobre 2026 au 31 octobre 2026</p>'.$this->table(
            ['Date', 'Nature des opérations', 'Valeur', 'Débit', 'Crédit'],
            [
                ['', 'SOLDE CREDITEUR AU 30.09.2026', '', '', '2 104,33'],
                ['02.10', 'FACTURE CARTE DU 011026 POINT P<br>CARTE 4974XXXXXXXX1234', '02.10', '312,40', ''],
                ['05.10', 'SARL MARTIN<br>FACTURE 2026-044', '05.10', '', '3 600,00'],
                ['14.10', 'CEDEO MASSY', '14.10', '1.204,10', ''],
                ['', 'TOTAL DES OPERATIONS', '', '1 516,50', '3 600,00'],
            ],
            [3 => 'right', 4 => 'right'],
        ));
        $this->assertSame([
            ['2026-10-02', 'FACTURE CARTE DU 011026 POINT P CARTE 4974XXXXXXXX1234', -31240],
            ['2026-10-05', 'SARL MARTIN FACTURE 2026-044', 360000],
            ['2026-10-14', 'CEDEO MASSY', -120410],
        ], $this->read($bnp));

        // Un seul montant, signe séparé par une espace.
        $qonto = $this->html('<p>Relevé du 01/10/2026 au 31/10/2026</p>'.$this->table(
            ['Date', 'Opération', 'Montant'],
            [['03/10', 'Brico Dépôt Evry', '- 89,90 €'], ['04/10', 'MARTIN SAS', '+ 1 200,00 €']],
            [2 => 'right'],
        ));
        $this->assertSame([['2026-10-03', 'Brico Dépôt Evry', -8990], ['2026-10-04', 'MARTIN SAS', 120000]], $this->read($qonto));

        // Dates en lettres, colonnes Sortant / Entrant / Solde ; « Total Energies » est bien une opération.
        $online = $this->html('<p>Relevé d\'octobre 2026</p>'.$this->table(
            ['Date', 'Description', 'Sortant', 'Entrant', 'Solde'],
            [['3 oct. 2026', 'Total Energies', '62,15 €', '', '1 937,85 €'], ['5 oct. 2026', 'Paul Durand', '', '600,00 €', '2 537,85 €']],
            [2 => 'right', 3 => 'right', 4 => 'right'],
        ));
        $this->assertSame([['2026-10-03', 'Total Energies', -6215], ['2026-10-05', 'Paul Durand', 60000]], $this->read($online));

        // « Débit (€) Crédit (€) » dans un seul titre ; « 45,20- » pour une sortie.
        $merged = $this->html('<p>Situation au 31/10/2026</p><table style="width:100%"><tr><th align="left">Date</th><th align="left">Opérations</th><th align="right" colspan="2">Débit (€)&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Crédit (€)</th></tr>'
            .'<tr><td>02/10</td><td>INTERMARCHE</td><td align="right">37,12</td><td></td></tr>'
            .'<tr><td>06/10</td><td>M DUPONT JEAN</td><td></td><td align="right">250,00</td></tr></table>');
        $this->assertSame([['2026-10-02', 'INTERMARCHE', -3712], ['2026-10-06', 'M DUPONT JEAN', 25000]], $this->read($merged));
        $minus = $this->html('<p>Extrait du 01/10/2026 au 31/10/2026</p>'.$this->table(
            ['Date', 'Libellé', 'Montant'],
            [['02/10/2026', 'CB MR BRICOLAGE', '120,50-'], ['04/10/2026', 'CLIENT DUPONT', '2 000,00']],
            [2 => 'right'],
        ));
        $this->assertSame([['2026-10-02', 'CB MR BRICOLAGE', -12050], ['2026-10-04', 'CLIENT DUPONT', 200000]], $this->read($minus));
    }

    public function test_text_written_letter_by_letter_or_inside_a_block(): void
    {
        $lines = [
            [760, [[40, 'Releve du 01/10/2026 au 31/10/2026']]],
            [730, [[40, 'Date'], [100, 'Libelle'], [380, 'Debit'], [470, 'Credit']]],
            [710, [[40, '02/10'], [100, 'CB CARREFOUR MARKET'], [380, '45,20']]],
            [695, [[40, '05/10'], [100, 'SARL MARTIN FACT 044'], [470, '1 500,00']]],
        ];
        $expected = [['2026-10-02', 'CB CARREFOUR MARKET', -4520], ['2026-10-05', 'SARL MARTIN FACT 044', 150000]];

        $this->assertSame($expected, $this->read($this->raw($this->textOps($lines, true))));
        // Tableau dans un bloc affiché par la page (relevé ré-imprimé en PDF) : colonnes comprises.
        $this->assertSame($expected, $this->read($this->raw("q 0.96 0 0 0.96 10 12 cm /Fm1 Do Q\n", $this->textOps($lines, false))));
    }

    public function test_why_a_pdf_is_not_read_is_explained_without_its_data(): void
    {
        $upload = fn (string $pdf) => $this->post(route('import.preview'), ['account_id' => $this->account->id, 'files' => [UploadedFile::fake()->createWithContent('releve.pdf', $pdf)]]);

        // Une photo du relevé : pas de texte.
        $image = imagecreatetruecolor(200, 80);
        ob_start();
        imagepng($image);
        $upload($this->html('<img src="data:image/png;base64,'.base64_encode((string) ob_get_clean()).'">'))
            ->assertSessionHasErrors(['file' => 'Ce PDF ne contient qu\'une image (scan, photo ou capture d\'écran) : il n\'y a aucun texte à lire. Prenez le relevé PDF de la banque (dans son app ou sur son site, rubrique Relevés / Documents), ou l\'export CSV des opérations.']);

        // Du texte, mais pas d'opération reconnue : détails techniques sans les données.
        $upload($this->html('<p>Madame Josette Lambert</p><p>Opération 7741 : achat chez Fromagerie Bertin pour 12 euros 40</p>'))
            ->assertSessionHas('import_issue', 'layout');
        $report = (string) session('import_report');
        $this->assertStringContainsString('Fichier 1 : layout', $report);
        $this->assertStringContainsString('aaaaaaaaa 9999', $report);
        foreach (['Josette', 'Lambert', 'Fromagerie', 'Bertin', '7741'] as $private) {
            $this->assertStringNotContainsString($private, $report);
        }
        $this->get(route('import.create'))->assertSee('Détails techniques')->assertSee('Copier les détails');
    }
}

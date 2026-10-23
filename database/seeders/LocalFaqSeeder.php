<?php

namespace Database\Seeders;

use App\Actions\Content\CreateFaqEntryAction;
use App\Models\AppTextKey;
use App\Models\Staff;
use Illuminate\Database\Seeder;

/**
 * The prototype's nine Help questions as **unpublished drafts** for local development
 * (spec 019 US5, T050). Nothing is published here: customers see no Help entry until a
 * person with `content.edit` checks the wording and presses Publish changes. Numbers the
 * Backend owns are setting placeholders, so they cannot go stale. The Arabic is AR-DRAFT
 * (spec Appendix B) and waits for the owner's review. Idempotent; local/testing only.
 */
class LocalFaqSeeder extends Seeder
{
    public function run(CreateFaqEntryAction $create): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalFaqSeeder skipped: only runs in local/testing.');

            return;
        }

        $author = Staff::query()->orderBy('created_at')->first();
        if ($author === null) {
            $this->command?->warn('LocalFaqSeeder skipped: no staff member to author the drafts.');

            return;
        }

        foreach ($this->entries() as $slug => [$qEn, $qAr, $aEn, $aAr]) {
            if (AppTextKey::query()->whereKey('help.faq.'.$slug.'.question')->exists()) {
                continue;
            }
            $create->handle($author, $slug, [
                'question_en' => $qEn, 'question_ar' => $qAr, 'answer_en' => $aEn, 'answer_ar' => $aAr, 'is_hidden' => false,
            ]);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    private function entries(): array
    {
        return [
            'more_than_jeweller' => [
                'Why would I get more than a jeweller?',
                'ليه هاخد أكتر من الصايغ؟',
                'A jeweller melts the piece, so he pays for the metal and keeps the craft. Here another woman buys the piece as it is, so the making charge comes back to you instead.',
                'الصايغ بيدوّب القطعة، فبيدفعلك تمن الدهب بس وبياخد المصنعية. هنا واحدة تانية بتشتري القطعة زي ما هي، فالمصنعية بترجعلك إنتي.',
            ],
            'is_it_halal' => [
                'Is this halal?',
                'الموضوع ده حلال؟',
                'Gold is handed over and paid for in the same transaction, with no interest and no borrowing. If you want to be certain for your own case, ask someone you trust for a ruling.',
                'الدهب بيتسلّم وبيتدفع تمنه في نفس المعاملة، من غير فايدة ومن غير سلف. ولو عايزة تتأكدي في حالتك، اسألي حد بتثقي فيه يفتيكي.',
            ],
            'lost_or_damaged' => [
                'What if my piece is lost or damaged at IGI?',
                'لو قطعتي ضاعت أو اتلفت عند IGI؟',
                'Pieces are insured while they are with IGI. If something happens, you are compensated and we recover it from them, not from you.',
                'القطع متأمّن عليها وهي عند IGI. لو حصل أي حاجة بتتعوّضي، واحنا بنرجّع حقنا منهم مش منك.',
            ],
            'when_do_i_get_paid' => [
                'When exactly do I get my money?',
                'هاخد فلوسي امتى بالظبط؟',
                'The full amount — gold value and making charge — reaches your wallet the moment the buyer pays the balance. Withdrawals reach your bank within one working day.',
                'المبلغ كله — تمن الدهب والمصنعية — بيوصل محفظتك لحظة ما المشتري يدفع الباقي. والسحب بيوصل حسابك البنكي في خلال يوم عمل.',
            ],
            'why_trust_you' => [
                'Why should I trust you with my gold?',
                'أثق فيكم ليه بدهبي؟',
                'You never hand your piece to a stranger. It goes to IGI, a certification lab, and stays there until the buyer has paid in full. Money is held by Dahab the whole time and never passes between two people.',
                'إنتي مبتسلّميش قطعتك لحد غريب. بتروح IGI، معمل اعتماد، وبتفضل هناك لحد ما المشتري يدفع كامل المبلغ. والفلوس بتفضل عند دهب طول الوقت ومبتتنقلش من شخص لشخص.',
            ],
            'nobody_buys' => [
                'What if nobody buys it?',
                'لو محدّش اشترى؟',
                'Nothing is charged and nothing is lost. Your piece stays listed, you can change the making charge whenever no request is open, or take it down and keep it.',
                'مفيش حاجة بتتحاسبي عليها ومفيش حاجة بتضيع. قطعتك بتفضل معروضة، وتقدري تغيّري المصنعية طول ما مفيش طلب مفتوح، أو تشيليها وتحتفظي بيها.',
            ],
            'piece_is_fake' => [
                'What if the piece turns out to be fake?',
                'لو القطعة طلعت مضروبة؟',
                "If IGI finds the gold is not what it claims to be, the buyer is refunded in full from escrow straight away and the seller's account is suspended.",
                'لو IGI لقت إن الدهب مش زي ما متقال، المشتري بيترجعله كامل المبلغ من الضمان فورًا وحساب البائعة بيتوقّف.',
            ],
            'what_does_it_cost' => [
                'What does it cost me?',
                'هتكلّفني كام؟',
                'Listing is free and inspection is free. Dahab takes {commission_gold_pct}% of the making charge you recover on gold, {commission_stone_pct}% of the full price on a diamond piece, with gold or without, and never less than {commission_minimum_egp} EGP.',
                'العرض ببلاش والفحص ببلاش. دهب بتاخد {commission_gold_pct}% من المصنعية اللي بترجعلك على الدهب، و{commission_stone_pct}% من السعر كله على قطعة الألماظ، فيها دهب أو لأ، ومش أقل من {commission_minimum_egp} جنيه.',
            ],
            'change_my_mind' => [
                'Can I change my mind after listing?',
                'أقدر أغيّر رأيي بعد ما أعرض؟',
                'Yes, as long as nobody has an open request on the piece. Once someone has asked to buy at your price, accept or decline first.',
                'أيوه، طول ما مفيش حد قدّم طلب على القطعة. أول ما حد يطلب يشتري بسعرك، لازم تقبلي أو ترفضي الأول.',
            ],
        ];
    }
}

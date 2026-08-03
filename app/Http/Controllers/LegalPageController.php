<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LegalPageController extends Controller
{
    private const EFFECTIVE_DATE = '2026-08-03';
    private const SUPPORTED_LANGUAGES = ['en', 'ar', 'ku'];

    public function privacyPolicy(Request $request)
    {
        return $this->page($request, 'privacy-policy');
    }

    public function termsAndConditions(Request $request)
    {
        return $this->page($request, 'terms-and-conditions');
    }

    public function dataAndCompliance(Request $request)
    {
        return $this->page($request, 'data-and-compliance');
    }

    private function page(Request $request, string $slug)
    {
        $language = $this->resolveLanguage($request);
        $page = $this->pages()[$slug][$language] ?? $this->pages()[$slug]['en'];

        return $this->apiSuccess([
            'slug' => $slug,
            'language' => $language,
            'direction' => $language === 'en' ? 'ltr' : 'rtl',
            'available_languages' => self::SUPPORTED_LANGUAGES,
            'title' => $page['title'],
            'effective_date' => self::EFFECTIVE_DATE,
            'summary' => $page['summary'],
            'sections' => $page['sections'],
            'content' => $this->plainTextContent($page['summary'], $page['sections']),
        ], "{$page['title']} retrieved successfully");
    }

    private function resolveLanguage(Request $request): string
    {
        $language = strtolower((string) $request->query('lang', ''));
        if (in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            return $language;
        }

        $acceptLanguage = strtolower((string) $request->header('Accept-Language', ''));
        foreach (self::SUPPORTED_LANGUAGES as $supported) {
            if (str_starts_with($acceptLanguage, $supported) || str_contains($acceptLanguage, ",{$supported}")) {
                return $supported;
            }
        }

        return 'en';
    }

    private function plainTextContent(string $summary, array $sections): string
    {
        $content = [$summary];

        foreach ($sections as $section) {
            $content[] = $section['heading'] . "\n" . $section['body'];
        }

        return implode("\n\n", $content);
    }

    private function pages(): array
    {
        return [
            'privacy-policy' => [
                'en' => [
                    'title' => 'Privacy Policy',
                    'summary' => 'This Privacy Policy explains how Our Quran collects, uses, stores, and protects information when you use our website, mobile applications, and related services.',
                    'sections' => [
                        [
                            'heading' => 'Information We Collect',
                            'body' => 'We may collect account information such as name, email address, login details, saved bookmarks, tags, preferences, selected translations, selected readings, and search activity when these features are used.',
                        ],
                        [
                            'heading' => 'How We Use Information',
                            'body' => 'We use information to provide Quran reading, audio, qiraat, books, search, saved preferences, account sync, security, support, and service improvement.',
                        ],
                        [
                            'heading' => 'Search and AI Features',
                            'body' => 'When semantic search or similar AI features are used, search queries may be processed to return relevant Quran results. Private account content is not used to train public AI models.',
                        ],
                        [
                            'heading' => 'Sharing and Service Providers',
                            'body' => 'We do not sell personal information. Limited information may be processed by hosting, database, analytics, security, email, storage, or AI providers only as needed to operate the service.',
                        ],
                        [
                            'heading' => 'Retention, Security, and Choices',
                            'body' => 'We keep data only as long as needed for service, security, support, backups, or legal obligations. Users may update preferences, remove saved content, or request account deletion where supported.',
                        ],
                    ],
                ],
                'ar' => [
                    'title' => 'سياسة الخصوصية',
                    'summary' => 'توضح سياسة الخصوصية هذه كيف يجمع تطبيق وموقع قرآننا المعلومات ويستخدمها ويحفظها ويحميها عند استخدامك للموقع أو تطبيقات الهاتف أو الخدمات المرتبطة بها.',
                    'sections' => [
                        [
                            'heading' => 'المعلومات التي نجمعها',
                            'body' => 'قد نجمع معلومات الحساب مثل الاسم والبريد الإلكتروني وبيانات تسجيل الدخول، وكذلك الإشارات المرجعية، والوسوم، والتفضيلات، والترجمات المختارة، والقراءات المختارة، ونشاط البحث عند استخدام هذه الميزات.',
                        ],
                        [
                            'heading' => 'كيف نستخدم المعلومات',
                            'body' => 'نستخدم المعلومات لتوفير قراءة القرآن، والصوتيات، والقراءات، والكتب، والبحث، وحفظ التفضيلات، ومزامنة الحساب، وحماية الخدمة، والدعم، وتحسين الأداء.',
                        ],
                        [
                            'heading' => 'البحث والميزات الذكية',
                            'body' => 'عند استخدام البحث الدلالي أو الميزات الذكية المشابهة، قد تتم معالجة عبارة البحث لإرجاع نتائج قرآنية مناسبة. لا نستخدم محتوى الحساب الخاص لتدريب نماذج ذكاء اصطناعي عامة.',
                        ],
                        [
                            'heading' => 'المشاركة ومزودو الخدمة',
                            'body' => 'لا نبيع المعلومات الشخصية. قد تتم معالجة معلومات محدودة بواسطة مزودي الاستضافة أو قواعد البيانات أو التحليلات أو الحماية أو البريد أو التخزين أو الذكاء الاصطناعي عند الحاجة لتشغيل الخدمة.',
                        ],
                        [
                            'heading' => 'الاحتفاظ والأمان والاختيارات',
                            'body' => 'نحتفظ بالبيانات فقط للمدة اللازمة لتقديم الخدمة أو الأمان أو الدعم أو النسخ الاحتياطي أو الالتزامات القانونية. يمكن للمستخدم تعديل التفضيلات أو حذف المحتوى المحفوظ أو طلب حذف الحساب عندما تكون هذه الميزة متاحة.',
                        ],
                    ],
                ],
                'ku' => [
                    'title' => 'سیاسەتی پاراستنی نهێنی',
                    'summary' => 'ئەم سیاسەتی پاراستنی نهێنییە ڕوون دەکاتەوە کە قورئانەکەمان چۆن زانیاری کۆدەکاتەوە، بەکاری دەهێنێت، هەڵیدەگرێت و دەپارێزێت کاتێک ماڵپەڕ، ئەپی مۆبایل، یان خزمەتگوزارییە پەیوەندیدارەکان بەکاردەهێنیت.',
                    'sections' => [
                        [
                            'heading' => 'ئەو زانیاریانەی کۆدەکرێنەوە',
                            'body' => 'لەوانەیە زانیاری هەژمار وەک ناو، ئیمەیڵ، زانیاری چوونەژوورەوە، نیشانەکراوەکان، تاگەکان، هەڵبژاردەکان، وەرگێڕان و خوێندنەوە هەڵبژێردراوەکان، و چالاکی گەڕان کۆبکرێنەوە کاتێک ئەم تایبەتمەندیانە بەکاردەهێنیت.',
                        ],
                        [
                            'heading' => 'چۆن زانیاری بەکاردەهێنین',
                            'body' => 'زانیاری بەکاردەهێنین بۆ پێشکەشکردنی خوێندنەوەی قورئان، دەنگ، قیرائات، پەرتووک، گەڕان، هەڵگرتنی هەڵبژاردەکان، هاوکاتکردنی هەژمار، پاراستنی خزمەتگوزاری، پشتگیری و باشترکردنی کارایی.',
                        ],
                        [
                            'heading' => 'گەڕان و تایبەتمەندییە زیرەکەکان',
                            'body' => 'کاتێک گەڕانی واتایی یان تایبەتمەندی زیرەکی هاوشێوە بەکاردەهێنرێت، دەربڕینی گەڕانەکە لەوانەیە پرۆسێس بکرێت بۆ گەڕاندنەوەی ئەنجامی گونجاوی قورئانی. ناوەڕۆکی تایبەتی هەژمار بۆ فێرکردنی مۆدێلی گشتی بەکارناهێنین.',
                        ],
                        [
                            'heading' => 'هاوبەشکردن و دابینکەرانی خزمەتگوزاری',
                            'body' => 'زانیاری کەسی نا فرۆشین. زانیاری سنووردار تەنها بەپێی پێویستی لەلایەن دابینکەرانی خانەخوێکردن، داتابەیس، شیکاری، پاراستن، ئیمەیڵ، هەڵگرتن یان خزمەتگوزاری زیرەکەکان پرۆسێس دەکرێت.',
                        ],
                        [
                            'heading' => 'ماوەی هەڵگرتن، پاراستن و هەڵبژاردەکان',
                            'body' => 'زانیاری تەنها ئەوەندە هەڵدەگرین کە بۆ خزمەتگوزاری، پاراستن، پشتگیری، وەشانەوەی پشتگیری یان پابەندی یاسایی پێویستە. بەکارهێنەر دەتوانێت هەڵبژاردەکان بگۆڕێت، ناوەڕۆکی هەڵگیراو بسڕێتەوە، یان داوای سڕینەوەی هەژمار بکات کاتێک ئەم تایبەتمەندییە هەبێت.',
                        ],
                    ],
                ],
            ],
            'terms-and-conditions' => [
                'en' => [
                    'title' => 'Terms & Conditions',
                    'summary' => 'These Terms & Conditions explain the rules for using Our Quran, including the website, mobile applications, APIs, Quran content, qiraat content, books, audio, translations, and search features.',
                    'sections' => [
                        [
                            'heading' => 'Acceptance of Terms',
                            'body' => 'By using Our Quran, you agree to use the service respectfully, lawfully, and according to these Terms. If you do not agree, you should stop using the service.',
                        ],
                        [
                            'heading' => 'Purpose of the Service',
                            'body' => 'Our Quran is provided to help users read, listen to, search, study, and benefit from Quran-related content, including qiraat differences, translations, tags, books, and educational material.',
                        ],
                        [
                            'heading' => 'Accounts and Acceptable Use',
                            'body' => 'Some features may require an account. You are responsible for your account activity and may not attack systems, overload services, bypass access controls, upload harmful files, or use the service unlawfully.',
                        ],
                        [
                            'heading' => 'Content Accuracy',
                            'body' => 'We work carefully to maintain accurate Quran text, qiraat data, translations, tags, books, and references. Important religious or scholarly matters should be verified with qualified scholars and reliable sources.',
                        ],
                        [
                            'heading' => 'Books, Downloads, and Rights',
                            'body' => 'Book downloads are provided through approved API endpoints. Access may be changed, restricted, or removed for operational, legal, or rights-related reasons. Platform code, design, and original features belong to their respective owners.',
                        ],
                    ],
                ],
                'ar' => [
                    'title' => 'الشروط والأحكام',
                    'summary' => 'توضح هذه الشروط والأحكام قواعد استخدام قرآننا، بما في ذلك الموقع، وتطبيقات الهاتف، وواجهات API، ومحتوى القرآن، والقراءات، والكتب، والصوتيات، والترجمات، وميزات البحث.',
                    'sections' => [
                        [
                            'heading' => 'قبول الشروط',
                            'body' => 'باستخدام قرآننا فإنك توافق على استخدام الخدمة باحترام وبطريقة قانونية ووفق هذه الشروط. إذا لم توافق على ذلك، ينبغي عليك التوقف عن استخدام الخدمة.',
                        ],
                        [
                            'heading' => 'هدف الخدمة',
                            'body' => 'تُقدَّم خدمة قرآننا لمساعدة المستخدمين على قراءة القرآن والاستماع إليه والبحث فيه ودراسته والاستفادة من المحتوى المرتبط به، بما في ذلك فروق القراءات، والترجمات، والوسوم، والكتب، والمواد التعليمية.',
                        ],
                        [
                            'heading' => 'الحسابات والاستخدام المقبول',
                            'body' => 'قد تتطلب بعض الميزات حساباً. أنت مسؤول عن نشاط حسابك، ولا يجوز مهاجمة الأنظمة، أو إرهاق الخدمة، أو تجاوز صلاحيات الوصول، أو رفع ملفات ضارة، أو استخدام الخدمة بطريقة غير قانونية.',
                        ],
                        [
                            'heading' => 'دقة المحتوى',
                            'body' => 'نعمل بعناية للحفاظ على دقة نص القرآن وبيانات القراءات والترجمات والوسوم والكتب والمراجع. وينبغي التحقق من المسائل الدينية أو العلمية المهمة مع أهل الاختصاص والمصادر الموثوقة.',
                        ],
                        [
                            'heading' => 'الكتب والتنزيلات والحقوق',
                            'body' => 'تُوفَّر تنزيلات الكتب من خلال واجهات API المعتمدة. وقد يتم تغيير الوصول أو تقييده أو إزالته لأسباب تشغيلية أو قانونية أو متعلقة بالحقوق. وتعود ملكية كود المنصة وتصميمها وميزاتها الأصلية إلى أصحابها المعنيين.',
                        ],
                    ],
                ],
                'ku' => [
                    'title' => 'مەرج و ڕێنماییەکان',
                    'summary' => 'ئەم مەرج و ڕێنماییانە ڕێساکانی بەکارهێنانی قورئانەکەمان ڕوون دەکەنەوە، لەوانە ماڵپەڕ، ئەپی مۆبایل، API، ناوەڕۆکی قورئان، قیرائات، پەرتووک، دەنگ، وەرگێڕان و تایبەتمەندییەکانی گەڕان.',
                    'sections' => [
                        [
                            'heading' => 'پەسەندکردنی مەرجەکان',
                            'body' => 'بە بەکارهێنانی قورئانەکەمان، ڕازیت کە خزمەتگوزارییەکە بە ڕێز، بە شێوەی یاسایی و بەپێی ئەم مەرجانە بەکاربهێنیت. ئەگەر ڕازی نیت، پێویستە واز لە بەکارهێنان بهێنیت.',
                        ],
                        [
                            'heading' => 'ئامانجی خزمەتگوزاری',
                            'body' => 'قورئانەکەمان بۆ یارمەتیدانی بەکارهێنەرانە لە خوێندنەوە، گوێگرتن، گەڕان، فێربوون و سوودبینین لە ناوەڕۆکی پەیوەندیدار بە قورئان، وەک جیاوازییەکانی قیرائات، وەرگێڕان، تاگ، پەرتووک و بابەتی فێرکاری.',
                        ],
                        [
                            'heading' => 'هەژمار و بەکارهێنانی دروست',
                            'body' => 'هەندێک تایبەتمەندی پێویستی بە هەژمارە. تۆ بەرپرسیاری چالاکی هەژمارەکەتیت و نابێت هێرش بکەیتە سەر سیستەمەکان، خزمەتگوزاری قورس بکەیت، سنووری دەسەڵات تێپەڕێنیت، فایل زیانبەخش باربکەیت، یان بە شێوەی نایاسایی بەکاری بهێنیت.',
                        ],
                        [
                            'heading' => 'دروستی ناوەڕۆک',
                            'body' => 'بە وریایی کاردەکەین بۆ پاراستنی دروستی دەقی قورئان، داتای قیرائات، وەرگێڕان، تاگ، پەرتووک و سەرچاوەکان. بابەتی گرنگی ئایینی یان زانستی پێویستە لەلایەن پسپۆڕان و سەرچاوەی باوەڕپێکراوەوە پشتڕاست بکرێتەوە.',
                        ],
                        [
                            'heading' => 'پەرتووک، داگرتن و مافەکان',
                            'body' => 'داگرتنی پەرتووکەکان لە ڕێگەی API پەسەندکراوەکانەوە پێشکەش دەکرێت. دەستگەیشتن لەوانەیە بگۆڕدرێت، سنووردار بکرێت یان لاببرێت بەهۆی پێویستی کارگێڕی، یاسایی یان مافەکان. کۆد، دیزاین و تایبەتمەندییە ئەسڵییەکانی پلاتفۆرم هی خاوەنە پەیوەندیدارەکانیانن.',
                        ],
                    ],
                ],
            ],
            'data-and-compliance' => [
                'en' => [
                    'title' => 'Data & Compliance',
                    'summary' => 'This Data & Compliance notice explains how Our Quran approaches data handling, security, user rights, third-party services, and operational compliance.',
                    'sections' => [
                        [
                            'heading' => 'Data We Store',
                            'body' => 'Depending on the features used, we may store user accounts, bookmarks, tags, preferences, search activity, app settings, operational logs, Quran content, qiraat data, books, and translations.',
                        ],
                        [
                            'heading' => 'Purpose and Access Controls',
                            'body' => 'Data is handled to provide features, maintain accounts, secure the service, improve reliability, support backups, and meet operational obligations. Administrative access should be limited to authorized maintainers.',
                        ],
                        [
                            'heading' => 'Backups, Logs, and Providers',
                            'body' => 'Backups and logs may be kept for reliability, debugging, abuse prevention, and disaster recovery. Hosting, database, analytics, storage, AI, email, or security providers may process limited data as needed.',
                        ],
                        [
                            'heading' => 'User Requests',
                            'body' => 'Where supported, users may request access, correction, export, or deletion of account-related data. Some records may be retained for security, backups, disputes, or legal obligations.',
                        ],
                        [
                            'heading' => 'Review and Incident Response',
                            'body' => 'Data practices should be reviewed as the service grows. If a security incident is identified, maintainers should investigate, reduce harm, restore secure operation, and notify affected parties when required.',
                        ],
                    ],
                ],
                'ar' => [
                    'title' => 'البيانات والامتثال',
                    'summary' => 'يوضح هذا الإشعار الخاص بالبيانات والامتثال كيفية تعامل قرآننا مع إدارة البيانات، والأمان، وحقوق المستخدمين، وخدمات الأطراف الثالثة، والامتثال التشغيلي.',
                    'sections' => [
                        [
                            'heading' => 'البيانات التي نخزنها',
                            'body' => 'بحسب الميزات المستخدمة، قد نخزن حسابات المستخدمين، والإشارات المرجعية، والوسوم، والتفضيلات، ونشاط البحث، وإعدادات التطبيق، والسجلات التشغيلية، ومحتوى القرآن، وبيانات القراءات، والكتب، والترجمات.',
                        ],
                        [
                            'heading' => 'الغرض وضوابط الوصول',
                            'body' => 'تُعالج البيانات لتوفير الميزات، وإدارة الحسابات، وحماية الخدمة، وتحسين الاعتمادية، ودعم النسخ الاحتياطي، والوفاء بالمتطلبات التشغيلية. وينبغي حصر الوصول الإداري بالمشرفين المخولين فقط.',
                        ],
                        [
                            'heading' => 'النسخ الاحتياطي والسجلات والمزودون',
                            'body' => 'قد تُحفظ النسخ الاحتياطية والسجلات لأغراض الاعتمادية، وتصحيح الأخطاء، ومنع إساءة الاستخدام، واستعادة الخدمة. وقد يعالج مزودو الاستضافة أو قواعد البيانات أو التحليلات أو التخزين أو الذكاء الاصطناعي أو البريد أو الأمان بيانات محدودة عند الحاجة.',
                        ],
                        [
                            'heading' => 'طلبات المستخدمين',
                            'body' => 'عندما تكون الميزة متاحة، يمكن للمستخدم طلب الوصول إلى بيانات الحساب أو تصحيحها أو تصديرها أو حذفها. وقد يتم الاحتفاظ ببعض السجلات لأغراض الأمان أو النسخ الاحتياطي أو النزاعات أو الالتزامات القانونية.',
                        ],
                        [
                            'heading' => 'المراجعة والاستجابة للحوادث',
                            'body' => 'ينبغي مراجعة ممارسات البيانات مع نمو الخدمة. وإذا تم اكتشاف حادث أمني، فعلى المشرفين التحقيق وتقليل الضرر واستعادة التشغيل الآمن وإبلاغ الأطراف المتأثرة عند الحاجة.',
                        ],
                    ],
                ],
                'ku' => [
                    'title' => 'داتا و پابەندبوون',
                    'summary' => 'ئەم ئاگادارکردنەوەی داتا و پابەندبوونە ڕوون دەکاتەوە قورئانەکەمان چۆن مامەڵە لەگەڵ بەڕێوەبردنی داتا، پاراستن، مافەکانی بەکارهێنەر، خزمەتگوزارییەکانی لایەنی سێیەم و پابەندبوونی کارگێڕی دەکات.',
                    'sections' => [
                        [
                            'heading' => 'ئەو داتایەی هەڵیدەگرین',
                            'body' => 'بەپێی ئەو تایبەتمەندیانەی بەکاردەهێنرێن، لەوانەیە هەژمار، نیشانەکراوەکان، تاگەکان، هەڵبژاردەکان، چالاکی گەڕان، ڕێکخستنەکانی ئەپ، تۆماری کارگێڕی، ناوەڕۆکی قورئان، داتای قیرائات، پەرتووک و وەرگێڕان هەڵبگرین.',
                        ],
                        [
                            'heading' => 'ئامانج و کۆنترۆڵی دەستگەیشتن',
                            'body' => 'داتا بۆ پێشکەشکردنی تایبەتمەندی، بەڕێوەبردنی هەژمار، پاراستنی خزمەتگوزاری، باشترکردنی متمانەپێکراوی، پشتگیری و پێداویستی کارگێڕی پرۆسێس دەکرێت. دەستگەیشتنی بەڕێوەبەرایەتی دەبێت تەنها بۆ کەسانی ڕێپێدراو سنووردار بێت.',
                        ],
                        [
                            'heading' => 'پشتگیری، تۆمارەکان و دابینکەران',
                            'body' => 'وەشانەوەی پشتگیری و تۆمارەکان لەوانەیە بۆ متمانەپێکراوی، چاککردنی هەڵە، ڕێگریکردن لە خراپ بەکارهێنان و گەڕاندنەوەی خزمەتگوزاری هەڵبگیرێن. دابینکەرانی خانەخوێکردن، داتابەیس، شیکاری، هەڵگرتن، زیرەکی، ئیمەیڵ یان پاراستن لەوانەیە داتای سنووردار پرۆسێس بکەن.',
                        ],
                        [
                            'heading' => 'داواکارییەکانی بەکارهێنەر',
                            'body' => 'کاتێک پشتیوانی دەکرێت، بەکارهێنەر دەتوانێت داوای دەستگەیشتن، ڕاستکردنەوە، هەناردەکردن یان سڕینەوەی داتای پەیوەندیدار بە هەژمار بکات. هەندێک تۆمار لەوانەیە بۆ پاراستن، پشتگیری، ناکۆکی یان پابەندی یاسایی بهێڵرێتەوە.',
                        ],
                        [
                            'heading' => 'پێداچوونەوە و وەڵامدانەوەی ڕووداو',
                            'body' => 'پراکتیزەکانی داتا دەبێت لەگەڵ گەشەکردنی خزمەتگوزاری پێداچوونەوەیان پێبکرێت. ئەگەر ڕووداوی پاراستن دۆزرایەوە، بەڕێوەبەران دەبێت لێکۆڵینەوە بکەن، زیان کەم بکەنەوە، کارکردنی پارێزراو بگەڕێننەوە و لایەنە کاریگەربووەکان ئاگادار بکەنەوە کاتێک پێویست بێت.',
                        ],
                    ],
                ],
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers;

class LegalPageController extends Controller
{
    public function privacyPolicy()
    {
        return $this->page([
            'privacy-policy',
            'Privacy Policy',
            'This Privacy Policy explains how Our Quran collects, uses, stores, and protects information when you use our website, mobile applications, and related services.',
            [
                [
                    'heading' => 'Information We Collect',
                    'body' => 'We may collect account information such as your name, email address, and login details when you create an account. We may also collect app activity such as saved bookmarks, tags, preferences, selected translations, selected readings, and search history when these features are used.',
                ],
                [
                    'heading' => 'How We Use Information',
                    'body' => 'We use information to provide the Quran reading experience, sync user preferences across devices, improve search and navigation, protect accounts, maintain service reliability, and respond to support or safety requests.',
                ],
                [
                    'heading' => 'Quran, Search, and AI Features',
                    'body' => 'When semantic search or related AI features are used, the search query may be processed to return relevant Quran results. We do not use private account content to train public AI models.',
                ],
                [
                    'heading' => 'Sharing of Information',
                    'body' => 'We do not sell personal information. We may share limited information with service providers only when needed to operate hosting, databases, analytics, security, email, or similar infrastructure.',
                ],
                [
                    'heading' => 'Data Retention',
                    'body' => 'We keep account and usage data only as long as needed to provide the service, meet security requirements, resolve issues, or comply with applicable obligations. Users may request account deletion where supported.',
                ],
                [
                    'heading' => 'Security',
                    'body' => 'We use reasonable technical and organizational safeguards to protect data, including access controls and secure transport where available. No online service can guarantee absolute security.',
                ],
                [
                    'heading' => 'Your Choices',
                    'body' => 'You may update account information, change preferences, remove saved content, or request deletion of your account where these features are available.',
                ],
                [
                    'heading' => 'Contact',
                    'body' => 'Questions about privacy may be sent to the Our Quran support or administration team through the contact method provided in the app or website.',
                ],
            ]
        ]);
    }

    public function termsAndConditions()
    {
        return $this->page([
            'terms-and-conditions',
            'Terms & Conditions',
            'These Terms & Conditions explain the rules for using Our Quran, including the website, mobile applications, APIs, Quran content, qiraat content, books, audio, translations, and search features.',
            [
                [
                    'heading' => 'Acceptance of Terms',
                    'body' => 'By using Our Quran, you agree to use the service respectfully, lawfully, and in accordance with these Terms. If you do not agree, you should stop using the service.',
                ],
                [
                    'heading' => 'Purpose of the Service',
                    'body' => 'Our Quran is provided to help users read, listen to, search, study, and benefit from Quran-related content, including qiraat differences, translations, tags, books, and educational material.',
                ],
                [
                    'heading' => 'Accounts',
                    'body' => 'Some features may require an account. You are responsible for keeping your login information secure and for activity that occurs under your account.',
                ],
                [
                    'heading' => 'Content Accuracy',
                    'body' => 'We work carefully to maintain accurate Quran text, qiraat data, translations, tags, books, and references. However, mistakes may occur. Users should verify important religious or scholarly matters with qualified scholars and reliable sources.',
                ],
                [
                    'heading' => 'Acceptable Use',
                    'body' => 'You may not misuse the service, attack or overload the systems, scrape excessive data, bypass access controls, upload harmful files, or use the service for unlawful, abusive, or misleading purposes.',
                ],
                [
                    'heading' => 'Books and Downloads',
                    'body' => 'Book downloads are provided through approved API endpoints. Access to books may be changed, restricted, or removed if required by operational, legal, or rights-related reasons.',
                ],
                [
                    'heading' => 'Intellectual Property',
                    'body' => 'The Our Quran platform, code, design, database structure, and original features belong to their respective owners. Quran text, translations, audio, books, and other materials may have their own rights and source terms.',
                ],
                [
                    'heading' => 'Changes to the Service',
                    'body' => 'We may update, improve, suspend, or remove features as needed. We may also update these Terms from time to time.',
                ],
                [
                    'heading' => 'Limitation of Liability',
                    'body' => 'The service is provided as available. To the fullest extent permitted by applicable law, Our Quran is not responsible for indirect losses, service interruptions, data loss, or reliance on content without verification.',
                ],
            ]
        ]);
    }

    public function dataAndCompliance()
    {
        return $this->page([
            'data-and-compliance',
            'Data & Compliance',
            'This Data & Compliance notice explains how Our Quran approaches data handling, security, user rights, third-party services, and operational compliance.',
            [
                [
                    'heading' => 'Data We Store',
                    'body' => 'Depending on the features used, we may store user accounts, bookmarks, tags, preferences, search-related activity, app settings, and operational logs. Quran content, qiraat data, books, and translations are stored as service content.',
                ],
                [
                    'heading' => 'Legal Basis and Purpose',
                    'body' => 'Data is handled to provide requested features, maintain user accounts, secure the service, improve reliability, support backups, and comply with applicable legal or operational obligations.',
                ],
                [
                    'heading' => 'Access Controls',
                    'body' => 'Administrative access should be limited to authorized maintainers. Sensitive operational credentials, database access, and server access should be protected using appropriate permissions and secrets management.',
                ],
                [
                    'heading' => 'Backups and Logs',
                    'body' => 'Backups and logs may be kept for reliability, debugging, abuse prevention, and disaster recovery. Retention periods may vary depending on operational needs.',
                ],
                [
                    'heading' => 'Third-Party Providers',
                    'body' => 'The service may rely on hosting, database, analytics, search, email, storage, AI, or security providers. These providers may process limited data only as needed to operate the service.',
                ],
                [
                    'heading' => 'User Requests',
                    'body' => 'Where supported, users may request access, correction, export, or deletion of account-related data. Some records may be retained where needed for security, backups, dispute resolution, or legal obligations.',
                ],
                [
                    'heading' => 'Children and Families',
                    'body' => 'Our Quran may be used by families and learners. Account features should be used with appropriate guidance where children are involved.',
                ],
                [
                    'heading' => 'Incident Response',
                    'body' => 'If a data or security incident is identified, maintainers should investigate, reduce harm, restore secure operation, and notify affected users or authorities when required.',
                ],
                [
                    'heading' => 'Ongoing Review',
                    'body' => 'Data and compliance practices should be reviewed as the service grows, especially when adding uploads, payments, analytics, AI features, or new user-generated content.',
                ],
            ]
        ]);
    }

    private function page(array $page)
    {
        [$slug, $title, $summary, $sections] = $page;

        return $this->apiSuccess([
            'slug' => $slug,
            'title' => $title,
            'effective_date' => '2026-08-03',
            'summary' => $summary,
            'sections' => $sections,
            'content' => $this->plainTextContent($summary, $sections),
        ], "{$title} retrieved successfully");
    }

    private function plainTextContent(string $summary, array $sections): string
    {
        $content = [$summary];

        foreach ($sections as $section) {
            $content[] = $section['heading'] . "\n" . $section['body'];
        }

        return implode("\n\n", $content);
    }
}

import { Head } from '@inertiajs/react';
import PublicShell from '@/components/commercial/public-shell';
import type { CommercialContact } from '@/types';

const SECTIONS: [string, string[]][] = [
    [
        'Quem somos',
        [
            'O VetorFlex é uma plataforma de gestão empresarial oferecida pela {company}. Esta política explica como tratamos dados pessoais ao usar o site e o sistema.',
        ],
    ],
    [
        'Dados que coletamos',
        [
            'Cadastro: nome e e-mail do responsável, nome da empresa e, se informado, CPF ou CNPJ.',
            'Uso do sistema: os dados que a sua empresa registra (clientes, produtos, vendas, contas e documentos), sob responsabilidade da própria empresa.',
            'Segurança: endereço IP e registros de acesso e de operações (auditoria).',
            'Contato comercial: nome, e-mail, telefone e mensagem enviados pelo formulário.',
        ],
    ],
    [
        'Para que usamos',
        [
            'Criar e manter a conta e o ambiente da empresa, com as aplicações escolhidas.',
            'Autenticação, prevenção de fraudes, auditoria e segurança da informação.',
            'Comunicações sobre a conta, o período de teste e a contratação.',
            'Responder a pedidos de contato.',
        ],
    ],
    [
        'Compartilhamento',
        [
            'Não vendemos dados pessoais.',
            'Integrações que a empresa ativar (por exemplo, emissão fiscal ou pagamentos) enviam aos provedores apenas o necessário para a operação, usando a conta da própria empresa.',
            'Dados podem ser informados a autoridades quando houver obrigação legal.',
        ],
    ],
    [
        'Armazenamento e segurança',
        [
            'Cada empresa acessa somente os próprios dados. Credenciais e certificados de integrações são guardados criptografados e nunca são exibidos.',
            'Os dados são mantidos enquanto a conta existir e pelo prazo exigido por obrigações legais. Encerrado o teste sem contratação, os dados ficam preservados para consulta até o pedido de exclusão.',
        ],
    ],
    [
        'Seus direitos (LGPD)',
        [
            'Você pode pedir confirmação, acesso, correção, portabilidade e exclusão dos seus dados pessoais, além de informações sobre o compartilhamento, pelo canal de contato abaixo.',
        ],
    ],
];

export default function Privacy({
    version,
    contact,
}: {
    version: string;
    contact: CommercialContact;
}) {
    return (
        <PublicShell company={contact.company}>
            <Head title="Política de privacidade" />
            <article className="space-y-6">
                <header className="space-y-1">
                    <h1 className="text-3xl font-semibold">
                        Política de privacidade
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Versão {version}
                    </p>
                </header>
                {SECTIONS.map(([title, paragraphs]) => (
                    <section key={title} className="space-y-2">
                        <h2 className="text-lg font-medium">{title}</h2>
                        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                            {paragraphs.map((text) => (
                                <li key={text}>
                                    {text.replace('{company}', contact.company)}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                <section className="space-y-2">
                    <h2 className="text-lg font-medium">Contato</h2>
                    <p className="text-muted-foreground">
                        {contact.email ? (
                            <>
                                Escreva para{' '}
                                <a
                                    className="text-brand underline"
                                    href={`mailto:${contact.email}`}
                                >
                                    {contact.email}
                                </a>
                                .
                            </>
                        ) : (
                            'Use o formulário de contato na página inicial.'
                        )}
                    </p>
                </section>
            </article>
        </PublicShell>
    );
}

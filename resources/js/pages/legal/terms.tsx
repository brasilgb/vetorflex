import { Head } from '@inertiajs/react';
import PublicShell from '@/components/commercial/public-shell';
import type { CommercialContact } from '@/types';

export default function Terms({
    version,
    trialDays,
    contact,
}: {
    version: string;
    trialDays: number;
    contact: CommercialContact;
}) {
    const sections: [string, string[]][] = [
        [
            'Objeto',
            [
                `Estes termos regem o uso do VetorFlex, plataforma de gestão oferecida pela ${contact.company}, composta por aplicações contratadas individualmente.`,
            ],
        ],
        [
            'Conta e responsabilidade',
            [
                'O responsável pelo cadastro declara ter poderes para representar a empresa e mantém o sigilo da senha.',
                'A empresa é responsável pelos dados que registra e pelos acessos que concede aos seus usuários.',
            ],
        ],
        [
            'Período de teste',
            [
                `Novas empresas recebem ${trialDays} dias de teste das aplicações escolhidas, contados a partir da criação do ambiente.`,
                'Ao final do teste, os dados continuam disponíveis para consulta, e o registro de novas operações depende da contratação.',
                'Não há cobrança automática: a contratação é feita de forma expressa.',
            ],
        ],
        [
            'Aplicações e valores',
            [
                'As aplicações disponíveis, suas dependências e os valores vigentes são os apresentados no cardápio e na revisão do cadastro.',
                'Incluir ou remover aplicações não reinicia o período de teste. Remover uma aplicação preserva os dados já registrados.',
            ],
        ],
        [
            'Integrações fiscais e de pagamento',
            [
                'Emissão fiscal e recebimento por Pix usam a conta da própria empresa nos provedores homologados e exigem configuração (certificado digital, credenciais) antes da operação real.',
                'O conteúdo dos documentos fiscais e a classificação tributária são de responsabilidade da empresa e de seu contador.',
            ],
        ],
        [
            'Disponibilidade e suporte',
            [
                'Trabalhamos para manter o serviço disponível e seguro; manutenções programadas serão comunicadas quando possível.',
            ],
        ],
        [
            'Encerramento',
            [
                'A empresa pode solicitar o encerramento e a exportação dos dados. A plataforma pode suspender o acesso em caso de uso indevido, preservando os dados.',
            ],
        ],
        [
            'Privacidade',
            ['O tratamento de dados pessoais segue a Política de privacidade.'],
        ],
    ];

    return (
        <PublicShell company={contact.company}>
            <Head title="Termos de uso" />
            <article className="space-y-6">
                <header className="space-y-1">
                    <h1 className="text-3xl font-semibold">Termos de uso</h1>
                    <p className="text-sm text-muted-foreground">
                        Versão {version}
                    </p>
                </header>
                {sections.map(([title, paragraphs]) => (
                    <section key={title} className="space-y-2">
                        <h2 className="text-lg font-medium">{title}</h2>
                        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                            {paragraphs.map((text) => (
                                <li key={text}>{text}</li>
                            ))}
                        </ul>
                    </section>
                ))}
            </article>
        </PublicShell>
    );
}

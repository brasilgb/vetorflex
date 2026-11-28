import { Head, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { dashboard, settings as settingsRoute } from '@/routes/sales';
import { update } from '@/routes/sales/settings';

export default function SalesSettings({
    settings,
}: {
    settings: { allow_anonymous_customer: boolean };
}) {
    const form = useForm({
        allow_anonymous_customer: settings.allow_anonymous_customer,
    });

    return (
        <>
            <Head title="Configurações de Vendas" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Configurações de Vendas"
                    description="Regras válidas para orçamentos e vendas da empresa."
                />
                <form
                    className="max-w-2xl space-y-4 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update.url(), { preserveScroll: true });
                    }}
                >
                    <CheckboxField
                        id="allow-anonymous"
                        label="Permitir venda sem identificação do cliente"
                        checked={form.data.allow_anonymous_customer}
                        error={form.errors.allow_anonymous_customer}
                        onChange={(checked) =>
                            form.setData('allow_anonymous_customer', checked)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Desligado, todo pedido de venda exige um cliente. O PDV
                        tem configuração própria.
                    </p>
                    <Button disabled={form.processing}>Salvar</Button>
                </form>
            </div>
        </>
    );
}

SalesSettings.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Configurações', href: settingsRoute() },
    ],
};

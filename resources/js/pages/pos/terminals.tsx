import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { posBreadcrumb } from '@/components/pos/pos-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { index, store, update } from '@/routes/pos/terminals';
import type { PosTerminal } from '@/types';

type Member = { id: number; name: string };

function TerminalDialog({
    terminal,
    members,
}: {
    terminal: PosTerminal | null;
    members: Member[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        name: terminal?.name ?? '',
        code: terminal?.code ?? '',
        location: terminal?.location ?? '',
        is_active: terminal?.is_active ?? true,
        receipt_width: String(terminal?.receipt_width ?? 80),
        operators: terminal?.operators.map((operator) => operator.id) ?? [],
    };
    const form = useForm(initial);
    const toggleOperator = (id: number, checked: boolean) =>
        form.setData(
            'operators',
            checked
                ? [...form.data.operators, id]
                : form.data.operators.filter((operator) => operator !== id),
        );

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData(initial);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                {terminal === null ? (
                    <Button>Novo terminal</Button>
                ) : (
                    <Button variant="outline" size="sm">
                        Editar
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        };

                        if (terminal === null) {
                            form.post(store.url(), options);
                        } else {
                            form.put(update.url(terminal.id), options);
                        }
                    }}
                >
                    <DialogTitle>
                        {terminal === null
                            ? 'Novo terminal'
                            : `Editar ${terminal.name}`}
                    </DialogTitle>
                    <DialogDescription>
                        Ponto de atendimento com caixa próprio. Sem operadores
                        definidos, qualquer usuário com permissão de abrir caixa
                        pode usá-lo.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="terminal-name"
                            label="Nome"
                            error={form.errors.name}
                        >
                            <Input
                                id="terminal-name"
                                required
                                maxLength={80}
                                placeholder="Caixa 01"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="terminal-code"
                            label="Identificação"
                            error={form.errors.code}
                        >
                            <Input
                                id="terminal-code"
                                required
                                maxLength={30}
                                placeholder="CX01"
                                value={form.data.code}
                                onChange={(event) =>
                                    form.setData('code', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="terminal-location"
                            label="Localização (opcional)"
                            error={form.errors.location}
                        >
                            <Input
                                id="terminal-location"
                                maxLength={120}
                                value={form.data.location}
                                onChange={(event) =>
                                    form.setData('location', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="terminal-width"
                            label="Largura do comprovante"
                            error={form.errors.receipt_width}
                        >
                            <NativeSelect
                                id="terminal-width"
                                value={form.data.receipt_width}
                                onChange={(event) =>
                                    form.setData(
                                        'receipt_width',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="80">Térmica 80 mm</option>
                                <option value="58">Térmica 58 mm</option>
                            </NativeSelect>
                        </FormField>
                    </div>
                    <CheckboxField
                        id="terminal-active"
                        label="Terminal ativo"
                        checked={form.data.is_active}
                        error={form.errors.is_active}
                        onChange={(checked) =>
                            form.setData('is_active', checked)
                        }
                    />
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Operadores autorizados
                        </legend>
                        {members.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum usuário ativo na empresa.
                            </p>
                        ) : (
                            <div className="grid max-h-48 gap-2 overflow-y-auto sm:grid-cols-2">
                                {members.map((member) => (
                                    <CheckboxField
                                        key={member.id}
                                        id={`operator-${terminal?.id ?? 'new'}-${member.id}`}
                                        label={member.name}
                                        checked={form.data.operators.includes(
                                            member.id,
                                        )}
                                        onChange={(checked) =>
                                            toggleOperator(member.id, checked)
                                        }
                                    />
                                ))}
                            </div>
                        )}
                        {form.errors.operators && (
                            <p className="text-sm text-destructive">
                                {form.errors.operators}
                            </p>
                        )}
                    </fieldset>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Salvar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PosTerminals({
    terminals,
    members,
}: {
    terminals: PosTerminal[];
    members: Member[];
}) {
    return (
        <>
            <Head title="Terminais do PDV" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Terminais"
                        description="Caixas e pontos de atendimento. Cada terminal tem no máximo uma sessão de caixa aberta."
                    />
                    <TerminalDialog terminal={null} members={members} />
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Terminal</th>
                                <th className="p-3">Localização</th>
                                <th className="p-3">Operadores</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {terminals.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhum terminal cadastrado.
                                    </td>
                                </tr>
                            )}
                            {terminals.map((terminal) => (
                                <tr
                                    key={terminal.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <span className="font-medium">
                                            {terminal.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {terminal.code} · comprovante{' '}
                                            {terminal.receipt_width} mm
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {terminal.location ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        {terminal.operators.length === 0
                                            ? 'Todos com permissão'
                                            : terminal.operators
                                                  .map(
                                                      (operator) =>
                                                          operator.name,
                                                  )
                                                  .join(', ')}
                                    </td>
                                    <td className="p-3">
                                        <span className="flex flex-wrap gap-1">
                                            <Badge
                                                variant={
                                                    terminal.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {terminal.is_active
                                                    ? 'Ativo'
                                                    : 'Inativo'}
                                            </Badge>
                                            {terminal.open_session && (
                                                <Badge>
                                                    Caixa aberto ·{' '}
                                                    {
                                                        terminal.open_session
                                                            .user_name
                                                    }
                                                </Badge>
                                            )}
                                        </span>
                                    </td>
                                    <td className="p-3 text-right">
                                        <TerminalDialog
                                            terminal={terminal}
                                            members={members}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

PosTerminals.layout = {
    breadcrumbs: [posBreadcrumb, { title: 'Terminais', href: index() }],
};

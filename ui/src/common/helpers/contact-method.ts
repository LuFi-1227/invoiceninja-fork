import { Client } from '$app/common/interfaces/client';
import { User } from '$app/common/interfaces/user';

export const VALID_CONTACT_METHODS = [
  'whatsapp_and_email',
  'whatsapp',
  'email',
  'both',
  'all',
];

export function isUserContactMethodDefined(user?: User | null): boolean {
  if (!user) {
    return false;
  }

  if (typeof user.has_contact_method === 'boolean') {
    return user.has_contact_method;
  }

  const method = (user.contact_method || user.forma_de_contato || '')
    .toString()
    .trim()
    .toLowerCase();

  return VALID_CONTACT_METHODS.includes(method);
}

export function userRequiresWhatsApp(user?: User | null): boolean {
  if (!user || !isUserContactMethodDefined(user)) return false;
  const method = (user.contact_method || user.forma_de_contato || '').toLowerCase();
  return method.includes('whatsapp') || method === 'both' || method === 'all';
}

export function userRequiresEmail(user?: User | null): boolean {
  if (!user || !isUserContactMethodDefined(user)) return false;
  const method = (user.contact_method || user.forma_de_contato || '').toLowerCase();
  return method.includes('email') || method === 'both' || method === 'all';
}

export function doesClientSatisfyContactMethod(
  user?: User | null,
  client?: Client | null
): { valid: boolean; missing: 'phone' | 'email' | null } {
  if (!user || !client) {
    return { valid: true, missing: null };
  }

  const requiresPhone = userRequiresWhatsApp(user);
  const requiresEmail = userRequiresEmail(user);

  const hasPhone = Boolean(
    client.phone || client.contacts?.some((c) => Boolean(c.phone))
  );

  const hasEmail = Boolean(
    client.contacts?.some((c) => Boolean(c.email))
  );

  if (requiresPhone && !hasPhone) {
    return { valid: false, missing: 'phone' };
  }

  if (requiresEmail && !hasEmail) {
    return { valid: false, missing: 'email' };
  }

  return { valid: true, missing: null };
}

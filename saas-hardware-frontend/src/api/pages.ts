import api from './axios';
import type { Page } from '../types';

export const getPages = async (): Promise<Page[]> => {
  const { data } = await api.get<Page[]>('/pages');
  return data;
};

export const getPage = async (id: string): Promise<Page> => {
  const { data } = await api.get<Page>(`/pages/${id}`);
  return data;
};

export const createPage = async (payload: Omit<Page, 'id' | 'tenant_id'>): Promise<Page> => {
  const { data } = await api.post<Page>('/pages', payload);
  return data;
};

export const updatePage = async (id: string, payload: Partial<Omit<Page, 'id' | 'tenant_id'>>): Promise<Page> => {
  const { data } = await api.put<Page>(`/pages/${id}`, payload);
  return data;
};

export const deletePage = async (id: string): Promise<void> => {
  await api.delete(`/pages/${id}`);
};

// Public page APIs
export const getPublicPages = async (slug: string): Promise<Page[]> => {
  const { data } = await api.get<Page[]>(`/public/${slug}/pages`);
  return data;
};

export const getPublicPageDetail = async (slug: string, pageSlug: string): Promise<Page> => {
  const { data } = await api.get<Page>(`/public/${slug}/pages/${pageSlug}`);
  return data;
};

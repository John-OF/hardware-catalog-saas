import api from './axios';
import type { Review, PaginatedResponse } from '../types';

export const getReviews = async (params?: {
  is_approved?: boolean | string;
  rating?: number;
  search?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<Review>> => {
  const { data } = await api.get<PaginatedResponse<Review>>('/reviews', { params });
  return data;
};

export const updateReviewApproval = async (id: string, isApproved: boolean): Promise<Review> => {
  const { data } = await api.put<Review>(`/reviews/${id}`, { is_approved: isApproved });
  return data;
};

export const deleteReview = async (id: string): Promise<void> => {
  await api.delete(`/reviews/${id}`);
};
